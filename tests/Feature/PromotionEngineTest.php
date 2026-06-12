<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
        $this->product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->product->update(['price' => 100, 'promo_price' => null, 'quantity' => 10]);
    }

    public function test_coupon_validation_and_percentage_discount(): void
    {
        $cart = $this->cart('coupon-cart');
        $promotion = $this->promotion('WELCOME10', 'percentage_discount', 10);
        $promotion->rules()->create(['rule_type' => 'minimum_order_amount', 'operator' => 'gte', 'value' => ['value' => 50]]);

        $this->withHeader('X-Cart-Session', 'coupon-cart')
            ->postJson('/api/v1/cart/coupon', ['code' => 'WELCOME10'])
            ->assertOk()
            ->assertJsonPath('data.coupon_code', 'WELCOME10')
            ->assertJsonPath('data.promotion_discount_total', 10);

        $this->assertSame('WELCOME10', $cart->fresh()->coupon_code);
    }

    public function test_invalid_coupon_is_rejected(): void
    {
        $this->cart('invalid-coupon-cart');

        $this->withHeader('X-Cart-Session', 'invalid-coupon-cart')
            ->postJson('/api/v1/cart/coupon', ['code' => 'NOPE'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.coupon.0', 'Coupon is invalid or cannot be applied to this cart.');
    }

    public function test_fixed_discount(): void
    {
        $cart = $this->cart('fixed-cart');
        $this->promotion(null, 'fixed_discount', 50);

        $result = app(PromotionEngineService::class)->evaluate($cart);

        $this->assertSame(50.0, $result['discount_total']);
    }

    public function test_free_shipping_promotion(): void
    {
        $cart = $this->cart('shipping-cart');
        $promotion = Promotion::query()->create($this->promotionPayload(null, 'free_shipping'));
        $promotion->actions()->create(['action_type' => 'free_shipping', 'configuration' => []]);

        $result = app(PromotionEngineService::class)->evaluate($cart, 8.99);

        $this->assertSame(8.99, $result['shipping_discount']);
    }

    public function test_category_and_brand_discounts(): void
    {
        $cart = $this->cart('scope-cart');
        $category = $this->promotion(null, 'category_discount', 5);
        $category->rules()->create(['rule_type' => 'category_id', 'operator' => 'equals', 'value' => ['value' => $this->product->category_id]]);
        $category->actions()->update(['configuration' => ['amount' => 5, 'scope' => 'category_id', 'scope_value' => $this->product->category_id]]);

        $brand = $this->promotion(null, 'brand_discount', 10, stackable: true);
        $brand->rules()->create(['rule_type' => 'brand_id', 'operator' => 'equals', 'value' => ['value' => $this->product->brand_id]]);
        $brand->actions()->update(['configuration' => ['amount' => 10, 'scope' => 'brand_id', 'scope_value' => $this->product->brand_id]]);

        $result = app(PromotionEngineService::class)->evaluate($cart);

        $this->assertSame(15.0, $result['discount_total']);
    }

    public function test_buy_x_get_y_discount(): void
    {
        $cart = $this->cart('bxy-cart', quantity: 2);
        $promotion = Promotion::query()->create($this->promotionPayload(null, 'buy_x_get_y'));
        $promotion->actions()->create(['action_type' => 'buy_x_get_y', 'configuration' => [
            'buy_product_id' => $this->product->id,
            'get_product_id' => $this->product->id,
            'buy_quantity' => 2,
            'get_quantity' => 1,
        ]]);

        $result = app(PromotionEngineService::class)->evaluate($cart);

        $this->assertSame(100.0, $result['discount_total']);
    }

    public function test_gift_product_is_added_to_cart(): void
    {
        $cart = $this->cart('gift-cart');
        $gift = Product::query()->where('id', '!=', $this->product->id)->firstOrFail();
        $gift->update(['active' => true, 'published_at' => now(), 'stock_status' => 'in_stock']);
        $promotion = Promotion::query()->create($this->promotionPayload(null, 'gift_product'));
        $promotion->actions()->create(['action_type' => 'gift_product', 'configuration' => ['product_id' => $gift->id, 'quantity' => 1]]);

        $cart = app(PromotionEngineService::class)->applyAutomaticGifts($cart);

        $this->assertTrue($cart->items->contains(fn (CartItem $item): bool => $item->product_id === $gift->id && $item->is_gift && (float) $item->total_price === 0.0));
    }

    public function test_bundle_discount(): void
    {
        $cart = $this->cart('bundle-cart');
        $second = Product::query()->where('id', '!=', $this->product->id)->firstOrFail();
        $second->update(['price' => 50, 'promo_price' => null, 'active' => true, 'published_at' => now(), 'stock_status' => 'in_stock']);
        $cart->items()->create(['product_id' => $second->id, 'quantity' => 1, 'unit_price' => 50, 'total_price' => 50]);

        $promotion = Promotion::query()->create($this->promotionPayload(null, 'bundle_discount'));
        $promotion->actions()->create(['action_type' => 'bundle_discount', 'configuration' => [
            'product_ids' => [$this->product->id, $second->id],
            'fixed_price' => 120,
        ]]);

        $result = app(PromotionEngineService::class)->evaluate($cart->fresh('items.product'));

        $this->assertSame(30.0, $result['discount_total']);
    }

    public function test_stacking_and_priority_resolution(): void
    {
        $cart = $this->cart('priority-cart');
        $this->promotion(null, 'fixed_discount', 10, priority: 1, stackable: false);
        $this->promotion(null, 'fixed_discount', 20, priority: 2, stackable: false);

        $result = app(PromotionEngineService::class)->evaluate($cart);

        $this->assertSame(20.0, $result['discount_total']);
        $this->assertCount(1, $result['applied_promotions']);
    }

    public function test_checkout_records_promotion_redemption(): void
    {
        $this->cart('checkout-promo-cart');
        $this->promotion('SUMMER2026', 'fixed_discount', 25);

        $this->withHeader('X-Cart-Session', 'checkout-promo-cart')
            ->postJson('/api/v1/cart/coupon', ['code' => 'SUMMER2026'])
            ->assertOk();

        $this->withHeader('X-Cart-Session', 'checkout-promo-cart')
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.discount_total', '25.00');

        $this->assertDatabaseHas('promotion_redemptions', [
            'session_id' => 'checkout-promo-cart',
            'discount_amount' => 25,
        ]);
        $this->assertDatabaseHas('marketing_events', ['event_name' => 'promotion_applied']);
    }

    public function test_loyalty_tier_rule_is_supported(): void
    {
        $user = User::factory()->create();
        $user->loyaltyAccount()->create(['points_balance' => 0, 'lifetime_points' => 5000, 'tier' => 'gold']);
        $cart = $this->cart('loyalty-promo-cart', user: $user);
        $promotion = $this->promotion(null, 'percentage_discount', 5);
        $promotion->rules()->create(['rule_type' => 'loyalty_tier', 'operator' => 'equals', 'value' => ['value' => 'gold']]);

        $result = app(PromotionEngineService::class)->evaluate($cart);

        $this->assertSame(5.0, $result['discount_total']);
    }

    private function cart(string $sessionId, int $quantity = 1, ?User $user = null): Cart
    {
        $cart = Cart::query()->create([
            'session_id' => $sessionId,
            'user_id' => $user?->id,
            'customer_email' => $user?->email,
            'status' => 'active',
        ]);

        $cart->items()->create([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 100,
            'total_price' => 100 * $quantity,
        ]);

        return $cart->fresh(['items.product', 'user.loyaltyAccount']);
    }

    private function promotion(?string $code, string $type, float $amount, int $priority = 0, bool $stackable = true): Promotion
    {
        $promotion = Promotion::query()->create($this->promotionPayload($code, $type, $priority, $stackable));
        $actionType = str_contains($type, 'discount') || $type === 'cart_discount' ? (str_contains($type, 'percentage') || in_array($type, ['category_discount', 'brand_discount'], true) ? 'percentage_discount' : 'fixed_discount') : $type;
        $promotion->actions()->create(['action_type' => $actionType, 'configuration' => ['amount' => $amount]]);

        return $promotion->fresh(['rules', 'actions']);
    }

    private function promotionPayload(?string $code, string $type, int $priority = 0, bool $stackable = true): array
    {
        return [
            'name' => $code ?: str($type)->headline()->toString(),
            'code' => $code,
            'type' => $type,
            'status' => 'active',
            'priority' => $priority,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'stackable' => $stackable,
            'stop_further_rules' => false,
        ];
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'promo@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'terms' => true,
        ];
    }
}
