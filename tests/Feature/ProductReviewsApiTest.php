<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\Reviews\ReviewModerationService;
use App\Services\Reviews\ReviewStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReviewsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_reviews_are_visible_publicly(): void
    {
        $product = Product::factory()->create();
        ProductReview::query()->create($this->reviewPayload($product, ['status' => 'approved', 'approved_at' => now()]));

        $this->getJson("/api/v1/products/{$product->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total_reviews', 1);
    }

    public function test_pending_reviews_are_hidden_publicly(): void
    {
        $product = Product::factory()->create();
        ProductReview::query()->create($this->reviewPayload($product, ['status' => 'pending']));

        $this->getJson("/api/v1/products/{$product->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.total_reviews', 0);
    }

    public function test_guest_review_submission_creates_pending_review(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/v1/products/{$product->slug}/reviews", [
            'customer_name' => 'Ivan',
            'customer_email' => 'ivan@example.com',
            'rating' => 5,
            'comment' => 'Много добър продукт.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'customer_email' => 'ivan@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_authenticated_review_submission_uses_user_identity(): void
    {
        $user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Petrova']);
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/products/{$product->slug}/reviews", [
                'rating' => 4,
                'comment' => 'Работи стабилно.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer_name', 'Maria Petrova');

        $this->assertDatabaseHas('product_reviews', ['user_id' => $user->id, 'product_id' => $product->id]);
    }

    public function test_duplicate_review_is_blocked(): void
    {
        $product = Product::factory()->create();

        $payload = [
            'customer_name' => 'Ivan',
            'customer_email' => 'ivan@example.com',
            'rating' => 5,
            'comment' => 'Много добър продукт.',
        ];

        $this->postJson("/api/v1/products/{$product->slug}/reviews", $payload)->assertCreated();
        $this->postJson("/api/v1/products/{$product->slug}/reviews", $payload)->assertUnprocessable();
    }

    public function test_invalid_rating_is_blocked(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/v1/products/{$product->slug}/reviews", [
            'customer_name' => 'Ivan',
            'customer_email' => 'ivan@example.com',
            'rating' => 6,
            'comment' => 'Bad rating.',
        ])->assertUnprocessable();
    }

    public function test_verified_purchase_detection(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $order = Order::query()->create($this->orderPayload($user));
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/products/{$product->slug}/reviews", [
                'rating' => 5,
                'comment' => 'Купен и тестван.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_verified_purchase', true);
    }

    public function test_voting_helpful_and_duplicate_vote_blocked(): void
    {
        $product = Product::factory()->create();
        $review = ProductReview::query()->create($this->reviewPayload($product, ['status' => 'approved']));

        $this->withHeader('X-Review-Session', '44444444-4444-4444-8444-444444444444')
            ->postJson("/api/v1/reviews/{$review->id}/vote", ['vote_type' => 'helpful'])
            ->assertCreated()
            ->assertJsonPath('data.vote_type', 'helpful');

        $this->withHeader('X-Review-Session', '44444444-4444-4444-8444-444444444444')
            ->postJson("/api/v1/reviews/{$review->id}/vote", ['vote_type' => 'helpful'])
            ->assertUnprocessable();
    }

    public function test_reporting_review(): void
    {
        $product = Product::factory()->create();
        $review = ProductReview::query()->create($this->reviewPayload($product, ['status' => 'approved']));

        $this->postJson("/api/v1/reviews/{$review->id}/report", [
            'reason' => 'spam',
            'message' => 'Looks fake',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_account_reviews_only_show_own_reviews(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $ownReview = ProductReview::query()->create($this->reviewPayload($product, ['user_id' => $user->id, 'customer_email' => $user->email]));
        $otherReview = ProductReview::query()->create($this->reviewPayload($product, ['user_id' => $other->id, 'customer_email' => $other->email]));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/account/reviews')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownReview->id])
            ->assertJsonMissing(['id' => $otherReview->id]);
    }

    public function test_admin_approve_and_reject_actions(): void
    {
        $product = Product::factory()->create();
        $review = ProductReview::query()->create($this->reviewPayload($product));
        $moderation = app(ReviewModerationService::class);

        $moderation->approve($review);
        $this->assertSame('approved', $review->refresh()->status);

        $moderation->reject($review, 'No useful content');
        $this->assertSame('rejected', $review->refresh()->status);
        $this->assertSame('No useful content', $review->rejection_reason);
    }

    public function test_rating_average_and_distribution(): void
    {
        $product = Product::factory()->create();
        ProductReview::query()->create($this->reviewPayload($product, ['rating' => 5, 'status' => 'approved', 'customer_email' => 'a@example.com']));
        ProductReview::query()->create($this->reviewPayload($product, ['rating' => 3, 'status' => 'approved', 'customer_email' => 'b@example.com']));
        ProductReview::query()->create($this->reviewPayload($product, ['rating' => 1, 'status' => 'pending', 'customer_email' => 'c@example.com']));

        $summary = app(ReviewStatsService::class)->summary($product);

        $this->assertSame(4.0, $summary['average_rating']);
        $this->assertSame(2, $summary['total_reviews']);
        $this->assertSame(1, $summary['rating_distribution'][5]);
        $this->assertSame(1, $summary['rating_distribution'][3]);
        $this->assertSame(0, $summary['rating_distribution'][1]);
    }

    private function reviewPayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'customer_name' => 'Ivan',
            'customer_email' => fake()->unique()->safeEmail(),
            'rating' => 5,
            'comment' => 'Много добър продукт.',
            'status' => 'pending',
            'is_verified_purchase' => false,
        ], $overrides);
    }

    private function orderPayload(User $user): array
    {
        return [
            'order_number' => 'MC'.fake()->unique()->numerify('######'),
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'customer_phone' => '0888123456',
            'customer_name' => $user->name,
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'paid',
            'shipping_method' => 'office',
            'shipping_status' => 'delivered',
            'status' => 'completed',
        ];
    }
}
