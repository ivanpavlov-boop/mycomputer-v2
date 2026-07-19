<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Marketing\FacebookCatalogService;
use App\Services\Marketing\MerchantFeedService;
use App\Services\Search\MeilisearchSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Tests\TestCase;

class ProductWorkflowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        Cache::flush();
    }

    public function test_only_fully_published_products_are_available_in_public_lists_and_detail_routes(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $visible = $this->product('WF-VISIBLE', Product::WORKFLOW_PUBLISHED, $category, $brand);

        foreach ([
            Product::WORKFLOW_DRAFT,
            Product::WORKFLOW_PENDING_REVIEW,
            Product::WORKFLOW_CHANGES_REQUESTED,
            Product::WORKFLOW_APPROVED,
        ] as $status) {
            $hidden = $this->product('WF-'.strtoupper($status), $status, $category, $brand);

            $this->getJson("/api/v1/products/{$hidden->slug}")->assertNotFound();
            $this->assertFalse(Product::query()->published()->whereKey($hidden)->exists());
        }

        $this->getJson('/api/v1/products?per_page=100')
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => 'WF-DRAFT'])
            ->assertJsonMissing(['sku' => 'WF-PENDING_REVIEW'])
            ->assertJsonMissing(['sku' => 'WF-CHANGES_REQUESTED'])
            ->assertJsonMissing(['sku' => 'WF-APPROVED']);

        $this->getJson("/api/v1/products/{$visible->slug}")
            ->assertOk()
            ->assertJsonPath('data.sku', $visible->sku);
    }

    public function test_public_catalog_search_home_and_recommendation_paths_share_the_published_scope(): void
    {
        $category = Category::factory()->create(['slug' => 'workflow-visibility-category']);
        $brand = Brand::factory()->create(['slug' => 'workflow-visibility-brand']);
        $parent = $this->product('WF-PARENT', Product::WORKFLOW_PUBLISHED, $category, $brand);
        $visible = $this->product('WF-RELATED-VISIBLE', Product::WORKFLOW_PUBLISHED, $category, $brand);
        $hidden = $this->product('WF-RELATED-HIDDEN', Product::WORKFLOW_APPROVED, $category, $brand);

        $parent->relatedProducts()->attach([$visible->id, $hidden->id]);
        $parent->accessoryProducts()->attach([$visible->id, $hidden->id]);

        $this->getJson("/api/v1/categories/{$category->slug}/products?per_page=100")
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);

        $this->getJson("/api/v1/brands/{$brand->slug}/products?per_page=100")
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);

        $this->getJson('/api/v1/search?q=WorkflowVisibility&per_page=100')
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);

        $this->getJson("/api/v1/products/{$parent->slug}/related")
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);

        $this->getJson("/api/v1/products/{$parent->slug}/accessories")
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);
    }

    public function test_sitemaps_and_marketing_feeds_exclude_non_public_workflow_states(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $visible = $this->product('WF-FEED-VISIBLE', Product::WORKFLOW_PUBLISHED, $category, $brand);
        $hidden = $this->product('WF-FEED-HIDDEN', Product::WORKFLOW_APPROVED, $category, $brand);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $merchant = app(MerchantFeedService::class)->xml();
        $facebook = app(FacebookCatalogService::class)->xml();

        $this->assertStringContainsString($visible->slug, $sitemap);
        $this->assertStringNotContainsString($hidden->slug, $sitemap);
        $this->assertStringContainsString($visible->sku, $merchant);
        $this->assertStringNotContainsString($hidden->sku, $merchant);
        $this->assertStringContainsString($visible->sku, $facebook);
        $this->assertStringNotContainsString($hidden->sku, $facebook);
    }

    public function test_search_index_eligibility_matches_public_visibility_and_read_paths_do_not_mutate_products(): void
    {
        $category = Category::factory()->create();
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $brand = Brand::factory()->create();
        $visible = $this->product('WF-INDEX-VISIBLE', Product::WORKFLOW_PUBLISHED, $category, $brand);

        $nonPublicProducts = collect([
            $this->product('WF-INDEX-DRAFT', Product::WORKFLOW_DRAFT, $category, $brand),
            $this->product('WF-INDEX-INACTIVE', Product::WORKFLOW_PUBLISHED, $category, $brand, ['active' => false]),
            $this->product('WF-INDEX-HIDDEN', Product::WORKFLOW_PUBLISHED, $category, $brand, ['product_status' => 'hidden']),
            $this->product('WF-INDEX-UNPUBLISHED', Product::WORKFLOW_PUBLISHED, $category, $brand, ['published_at' => null]),
            $this->product('WF-INDEX-NO-SLUG', Product::WORKFLOW_PUBLISHED, $category, $brand, ['slug' => '']),
            $this->product('WF-INDEX-CATEGORY', Product::WORKFLOW_PUBLISHED, $inactiveCategory, $brand),
        ]);

        $deleted = $this->product('WF-INDEX-DELETED', Product::WORKFLOW_PUBLISHED, $category, $brand);
        $deleted->delete();
        $nonPublicProducts->push($deleted);

        $snapshot = $nonPublicProducts->mapWithKeys(fn (Product $product): array => [
            $product->id => $this->visibilitySnapshot($product),
        ])->all();

        $this->assertTrue($visible->shouldBeSearchable());

        foreach ($nonPublicProducts as $product) {
            $this->assertFalse($product->shouldBeSearchable(), "{$product->sku} must not enter the public search index.");
        }

        $this->getJson('/api/v1/products?per_page=100')->assertOk();
        $this->getJson('/api/v1/search?q=WF-INDEX&per_page=100')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
        app(MerchantFeedService::class)->xml();
        app(FacebookCatalogService::class)->xml();

        foreach ($nonPublicProducts as $product) {
            $fresh = Product::withTrashed()->findOrFail($product->id);
            $this->assertSame($snapshot[$product->id], $this->visibilitySnapshot($fresh));
        }
    }

    public function test_stale_meilisearch_hits_are_rechecked_against_the_complete_public_boundary(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $visible = $this->product('WF-MEILI-VISIBLE', Product::WORKFLOW_PUBLISHED, $category, $brand);
        $hidden = $this->product('WF-MEILI-HIDDEN', Product::WORKFLOW_APPROVED, $category, $brand);
        $hiddenBefore = $this->visibilitySnapshot($hidden);

        config()->set('scout.driver', 'meilisearch');

        $manager = app(EngineManager::class);
        $manager->extend('meilisearch', fn (): Engine => new StaleWorkflowSearchEngine([
            $hidden->id,
            $visible->id,
        ]));
        $manager->forgetDrivers();

        $result = app(MeilisearchSearchService::class)->search([
            'q' => 'WF-MEILI',
            'per_page' => 20,
        ]);

        $this->assertSame('meilisearch', $result['engine']);
        $this->assertSame([$visible->id], collect($result['products']->items())->pluck('id')->all());
        $this->assertSame(1, $result['products']->total());
        $this->assertSame($hiddenBefore, $this->visibilitySnapshot($hidden->fresh()));
    }

    public function test_secondary_public_product_paths_reject_hidden_workflow_states(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $hidden = $this->product('WF-SECONDARY-HIDDEN', Product::WORKFLOW_APPROVED, $category, $brand);
        $user = User::factory()->create();
        $wishlist = Wishlist::query()->create([
            'user_id' => $user->id,
            'name' => 'Workflow visibility',
            'is_default' => true,
        ]);

        $this->getJson("/api/v1/products/{$hidden->slug}/bundles")
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/products/{$hidden->slug}/request-quote", ['quantity' => 1])
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/wishlists/{$wishlist->id}/items", ['product_id' => $hidden->id])
            ->assertUnprocessable();

        $this->postJson('/api/v1/compare/items', ['product_id' => $hidden->id])
            ->assertUnprocessable();
    }

    private function product(
        string $sku,
        string $workflowStatus,
        Category $category,
        Brand $brand,
        array $overrides = [],
    ): Product {
        $public = $workflowStatus === Product::WORKFLOW_PUBLISHED;

        return Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'sku' => $sku,
            'name' => "WorkflowVisibility {$sku}",
            'slug' => strtolower(str_replace('_', '-', $sku)),
            'workflow_status' => $workflowStatus,
            'product_status' => $public ? 'active' : 'hidden',
            'active' => $public,
            'published_at' => $public ? now() : null,
            'stock_status' => Product::STOCK_STATUS_IN_STOCK,
            'featured' => true,
            'new_product' => true,
            'bestseller' => true,
            'promo_price' => 40,
            'price' => 50,
        ], $overrides));
    }

    private function visibilitySnapshot(Product $product): array
    {
        return [
            'workflow_status' => $product->workflow_status,
            'product_status' => $product->product_status,
            'active' => (bool) $product->active,
            'published_at' => $product->getRawOriginal('published_at'),
            'slug' => $product->slug,
            'updated_at' => $product->getRawOriginal('updated_at'),
            'deleted_at' => $product->getRawOriginal('deleted_at'),
        ];
    }
}

final class StaleWorkflowSearchEngine extends Engine
{
    /** @param list<int> $productIds */
    public function __construct(private readonly array $productIds) {}

    public function update($models): void {}

    public function delete($models): void {}

    public function search(ScoutBuilder $builder): array
    {
        return $this->results($builder);
    }

    public function paginate(ScoutBuilder $builder, $perPage, $page): array
    {
        return $this->results($builder);
    }

    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('id')->values();
    }

    public function map(ScoutBuilder $builder, $results, $model)
    {
        $ids = $this->mapIds($results)->all();
        $positions = array_flip($ids);

        return $model->getScoutModelsByIds($builder, $ids)
            ->sortBy(fn ($record): int => $positions[$record->getScoutKey()])
            ->values();
    }

    public function lazyMap(ScoutBuilder $builder, $results, $model): LazyCollection
    {
        return LazyCollection::make(fn () => yield from $this->map($builder, $results, $model));
    }

    public function getTotalCount($results): int
    {
        return count($results['hits']);
    }

    public function flush($model): void {}

    public function createIndex($name, array $options = []): void {}

    public function deleteIndex($name): void {}

    private function results(ScoutBuilder $builder): array
    {
        $ids = $builder->model instanceof Product ? $this->productIds : [];

        return [
            'hits' => array_map(fn (int $id): array => ['id' => $id], $ids),
        ];
    }
}
