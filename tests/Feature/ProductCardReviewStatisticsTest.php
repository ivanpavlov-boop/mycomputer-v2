<?php

namespace Tests\Feature;

use App\Http\Resources\ProductCardResource;
use App\Models\Product;
use App\Models\ProductReview;
use App\Services\Reviews\ReviewStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductCardReviewStatisticsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('pageSizes')]
    public function test_review_queries_are_bounded_for_the_entire_serialized_page(int $productCount, bool $hasReviews): void
    {
        $products = Product::factory()->count($productCount)->create(['price' => 100]);

        if ($hasReviews) {
            foreach ($products as $product) {
                $this->review($product, 4);
                $this->review($product, 5);
            }
        }

        [$response, $queries] = $this->readOnlyGet('/api/v1/products?per_page=24');
        $response->assertJsonCount($productCount, 'data');
        $this->assertEqualsCanonicalizing($products->modelKeys(), array_column($response->json('data'), 'id'));

        foreach ($response->json('data') as $card) {
            $this->assertSame($hasReviews ? 2 : 0, $card['reviews_count']);
            $this->assertSame($hasReviews ? 4.5 : 0, $card['average_rating']);
            $this->assertArrayNotHasKey('rating_distribution', $card);
            $this->assertArrayNotHasKey('verified_reviews_count', $card);
        }

        $reviewQueries = $this->reviewQueries($queries);
        $this->assertCount(2, $reviewQueries, json_encode([
            'products' => $productCount,
            'has_reviews' => $hasReviews,
            'review_queries' => count($reviewQueries),
            'total_queries' => count($queries),
        ], JSON_THROW_ON_ERROR));

        foreach ($reviewQueries as $query) {
            $this->assertStringNotContainsString('is_verified_purchase', $query['query']);
        }
    }

    public static function pageSizes(): array
    {
        return [
            'one without reviews' => [1, false],
            'two without reviews' => [2, false],
            '24 without reviews' => [24, false],
            'one with reviews' => [1, true],
            'two with reviews' => [2, true],
            '24 with reviews' => [24, true],
        ];
    }

    public function test_paginated_cards_keep_approved_statistics_separate_and_ignore_hidden_reviews(): void
    {
        [$first, $second, $empty] = $this->ratedProducts();
        [$response] = $this->readOnlyGet('/api/v1/products?sort=price_asc&per_page=2');
        $response->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $cards = collect($response->json('data'))->keyBy('id');

        $this->assertSame([$first->id, $second->id], $cards->keys()->all());
        $this->assertSame(3.33, $cards[$first->id]['average_rating']);
        $this->assertSame(3, $cards[$first->id]['reviews_count']);
        $this->assertSame(1.67, $cards[$second->id]['average_rating']);
        $this->assertSame(3, $cards[$second->id]['reviews_count']);
        $this->assertArrayNotHasKey('approved_reviews_count', $cards[$first->id]);
        $this->assertArrayNotHasKey('approved_reviews_avg_rating', $cards[$first->id]);

        [$lastPage] = $this->readOnlyGet('/api/v1/products?sort=price_asc&per_page=2&page=2');
        $lastPage->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $empty->id)
            ->assertJsonPath('data.0.average_rating', 0)
            ->assertJsonPath('data.0.reviews_count', 0)
            ->assertJsonPath('meta.total', 3);
    }

    #[DataProvider('aggregateLoading')]
    public function test_card_resource_preserves_types_with_and_without_preloaded_approved_aggregates(string $loading, int $expectedQueries): void
    {
        [$rated, , $empty] = $this->ratedProducts();

        foreach ([[$rated, 3.33, 3], [$empty, 0.0, 0]] as [$product, $average, $count]) {
            $product = $product->fresh();

            if ($loading === 'approved') {
                app(ReviewStatsService::class)->loadCardSummaries($product->newCollection([$product]));
            } elseif ($loading === 'unfiltered') {
                $product->loadCount('reviews')->loadAvg('reviews', 'rating');
            }

            $before = $this->databaseSnapshot();
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                $card = ProductCardResource::make($product)->resolve();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            $this->assertSame($average, $card['average_rating']);
            $this->assertSame($count, $card['reviews_count']);
            $this->assertIsFloat($card['average_rating']);
            $this->assertIsInt($card['reviews_count']);
            $this->assertCount($expectedQueries, $this->reviewQueries($queries));
            $this->assertFalse($product->relationLoaded('reviews'));
            $this->assertSame($before, $this->databaseSnapshot());
            $this->assertNoWrites($queries);
        }
    }

    public static function aggregateLoading(): array
    {
        return [
            'not preloaded' => ['none', 1],
            'approved aggregates' => ['approved', 0],
            'unfiltered aggregates are not trusted' => ['unfiltered', 1],
        ];
    }

    public function test_full_summary_detail_and_reviews_api_keep_distribution_and_verified_counts(): void
    {
        [$product, , $empty] = $this->ratedProducts();
        $expected = [
            'average_rating' => 3.33,
            'total_reviews' => 3,
            'reviews_count' => 3,
            'verified_reviews_count' => 2,
            'rating_distribution' => [1 => 1, 2 => 0, 3 => 0, 4 => 1, 5 => 1],
        ];
        $emptySummary = [
            'average_rating' => 0.0,
            'total_reviews' => 0,
            'reviews_count' => 0,
            'verified_reviews_count' => 0,
            'rating_distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        ];
        $before = $this->databaseSnapshot();
        $stats = app(ReviewStatsService::class);
        $stats->loadCardSummaries($product->newCollection([$product, $empty]));
        $this->assertSame($expected, $stats->summary($product));
        $this->assertSame($emptySummary, $stats->summary($empty));
        $this->assertSame($before, $this->databaseSnapshot());

        // JSON resources preserve the existing zero-based distribution array.
        $expectedJson = [...$expected, 'rating_distribution' => [1, 0, 0, 1, 1]];
        $emptyDistributionJson = [0, 0, 0, 0, 0];
        [$detail] = $this->readOnlyGet("/api/v1/products/{$product->slug}");
        foreach (['average_rating', 'reviews_count', 'verified_reviews_count', 'rating_distribution'] as $field) {
            $this->assertSame($expectedJson[$field], $detail->json('data.'.$field));
        }

        [$reviews] = $this->readOnlyGet("/api/v1/products/{$product->slug}/reviews");
        $reviews->assertJsonCount(3, 'data');
        $this->assertSame($expectedJson, $reviews->json('summary'));

        [$emptyDetail] = $this->readOnlyGet("/api/v1/products/{$empty->slug}");
        $emptyDetail->assertJsonPath('data.average_rating', 0)
            ->assertJsonPath('data.reviews_count', 0)
            ->assertJsonPath('data.verified_reviews_count', 0)
            ->assertJsonPath('data.rating_distribution', $emptyDistributionJson);
        [$emptyReviews] = $this->readOnlyGet("/api/v1/products/{$empty->slug}/reviews");
        $emptyReviews->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.average_rating', 0)
            ->assertJsonPath('summary.total_reviews', 0)
            ->assertJsonPath('summary.rating_distribution', $emptyDistributionJson);
    }

    public function test_empty_catalog_does_not_query_reviews(): void
    {
        [$response, $queries] = $this->readOnlyGet('/api/v1/products');
        $response->assertJsonCount(0, 'data');
        $this->assertSame([], $this->reviewQueries($queries));
    }

    private function ratedProducts(): array
    {
        $first = Product::factory()->create(['price' => 100]);
        $second = Product::factory()->create(['price' => 200]);
        $empty = Product::factory()->create(['price' => 300]);

        foreach ([[5, true], [4, false], [1, true]] as [$rating, $verified]) {
            $this->review($first, $rating, ['is_verified_purchase' => $verified]);
        }

        foreach ([2, 2, 1] as $rating) {
            $this->review($second, $rating);
        }

        foreach ([$first, $second, $empty] as $product) {
            foreach (['pending', 'rejected', 'spam'] as $status) {
                $this->review($product, 5, ['status' => $status, 'is_verified_purchase' => true]);
            }

            $this->review($product, 5, ['is_verified_purchase' => true])->delete();
        }

        return [$first, $second, $empty];
    }

    private function review(Product $product, int $rating, array $overrides = []): ProductReview
    {
        return ProductReview::query()->create([
            'product_id' => $product->id,
            'customer_name' => 'Synthetic reviewer',
            'customer_email' => fake()->unique()->safeEmail(),
            'comment' => 'Synthetic review',
            'rating' => $rating,
            'status' => 'approved',
            'is_verified_purchase' => false,
            ...$overrides,
        ]);
    }

    /**
     * @return array{TestResponse, array}
     */
    private function readOnlyGet(string $url): array
    {
        $before = $this->databaseSnapshot();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $this->getJson($url)->assertOk();
            $response->json();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertNoWrites($queries);

        return [$response, $queries];
    }

    private function reviewQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'product_reviews')));
    }

    private function assertNoWrites(array $queries): void
    {
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query['query']);
        }
    }

    private function databaseSnapshot(): array
    {
        return collect(['products', 'product_reviews', 'product_attribute_values', 'supplier_products'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()])
            ->all();
    }
}
