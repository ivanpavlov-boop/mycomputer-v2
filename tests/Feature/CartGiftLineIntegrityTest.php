<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\MarketingEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Cart\CartLifecycleService;
use App\Services\Email\EmailMarketingService;
use App\Services\Promotions\PromotionEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartGiftLineIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
    }

    public function test_reconciliation_is_idempotent_and_logs_only_the_created_gift(): void
    {
        [$cart, $paid, $giftProduct] = $this->qualifyingCart();
        $promotion = $this->promotion(priority: 10);
        $promotion->rules()->create([
            'rule_type' => 'product_id',
            'operator' => 'equals',
            'value' => ['value' => $paid->id],
        ]);
        $promotion->actions()->create([
            'action_type' => 'gift_product',
            'configuration' => ['product_id' => $giftProduct->id, 'quantity' => 1],
        ]);

        $engine = app(PromotionEngineService::class);
        $engine->applyAutomaticGifts($cart);
        $gift = $cart->items()->gifts()->sole();
        $knownTimestamp = CarbonImmutable::parse('2026-01-01 12:00:00');
        DB::table('cart_items')->where('id', $gift->id)->update(['updated_at' => $knownTimestamp]);

        $engine->applyAutomaticGifts($cart->fresh());

        $this->assertSame(1, $cart->items()->gifts()->count());
        $this->assertSame($knownTimestamp->toDateTimeString(), $gift->fresh()->updated_at->toDateTimeString());
        $this->assertSame(1, $gift->fresh()->quantity);
        $this->assertSame(0.0, (float) $gift->fresh()->unit_price);
        $this->assertSame(
            1,
            MarketingEvent::query()
                ->where('event_name', 'gift_added')
                ->where('session_id', $cart->session_id)
                ->count(),
        );
    }

    public function test_multiple_gift_sources_aggregate_with_deterministic_primary_promotion(): void
    {
        [$cart, $paid, $giftProduct] = $this->qualifyingCart();
        $primary = $this->promotion(priority: 20);
        $secondary = $this->promotion(priority: 10);

        foreach ([[$primary, 2], [$secondary, 3]] as [$promotion, $quantity]) {
            $promotion->rules()->create([
                'rule_type' => 'product_id',
                'operator' => 'equals',
                'value' => ['value' => $paid->id],
            ]);
            $promotion->actions()->create([
                'action_type' => 'gift_product',
                'configuration' => ['product_id' => $giftProduct->id, 'quantity' => $quantity],
            ]);
        }

        app(PromotionEngineService::class)->applyAutomaticGifts($cart);

        $gift = $cart->items()->gifts()->sole();
        $this->assertSame(5, $gift->quantity);
        $this->assertSame($primary->id, $gift->promotion_id);
        $this->assertSame(0.0, (float) $gift->total_price);

        $primary->update(['status' => 'inactive']);
        app(PromotionEngineService::class)->applyAutomaticGifts($cart->fresh());

        $this->assertSame(3, $gift->fresh()->quantity);
        $this->assertSame($secondary->id, $gift->fresh()->promotion_id);

        $secondary->update(['status' => 'inactive']);
        app(PromotionEngineService::class)->applyAutomaticGifts($cart->fresh());

        $this->assertSame(0, $cart->items()->gifts()->count());
        $this->assertDatabaseHas('cart_items', ['id' => $cart->items()->paid()->sole()->id]);
    }

    public function test_paid_and_gift_copy_of_same_product_coexist_without_price_conversion(): void
    {
        $cart = $this->cart('same-product-gift');
        $product = Product::factory()->create(['quantity' => 20, 'price' => 40]);
        $cart->items()->create($this->paidLine($product, 2));
        $promotion = $this->promotion();
        $promotion->rules()->create([
            'rule_type' => 'product_id',
            'operator' => 'equals',
            'value' => ['value' => $product->id],
        ]);
        $promotion->actions()->create([
            'action_type' => 'gift_product',
            'configuration' => ['product_id' => $product->id, 'quantity' => 1],
        ]);

        app(PromotionEngineService::class)->applyAutomaticGifts($cart);

        $paid = $cart->items()->paid()->sole();
        $gift = $cart->items()->gifts()->sole();
        $this->assertSame($paid->product_id, $gift->product_id);
        $this->assertSame(2, $paid->quantity);
        $this->assertSame(1, $gift->quantity);
        $this->assertSame(40.0, (float) $paid->unit_price);
        $this->assertSame(0.0, (float) $gift->unit_price);
    }

    public function test_gifts_do_not_qualify_product_category_brand_quantity_or_subtotal_rules(): void
    {
        $cart = $this->cart('gift-rule-isolation');
        $giftProduct = Product::factory()->create(['quantity' => 20, 'price' => 500]);
        $cart->items()->create([
            'product_id' => $giftProduct->id,
            'quantity' => 50,
            'is_gift' => true,
            'unit_price' => 0,
            'total_price' => 0,
        ]);

        foreach ([
            ['product_id', $giftProduct->id],
            ['category_id', $giftProduct->category_id],
            ['brand_id', $giftProduct->brand_id],
            ['quantity_min', 1],
            ['minimum_order_amount', 1],
        ] as [$ruleType, $value]) {
            $promotion = $this->promotion();
            $promotion->rules()->create([
                'rule_type' => $ruleType,
                'operator' => 'gte',
                'value' => ['value' => $value],
            ]);
            $promotion->actions()->create([
                'action_type' => 'fixed_discount',
                'configuration' => ['amount' => 10],
            ]);
        }

        $result = app(PromotionEngineService::class)->evaluate($cart->fresh(['items.product']));

        $this->assertSame([], $result['applied_promotions']);
        $this->assertSame(0.0, $result['discount_total']);
    }

    public function test_removing_qualifying_paid_content_removes_only_derived_gift(): void
    {
        [$cart, $paid, $giftProduct] = $this->qualifyingCart();
        $promotion = $this->promotion();
        $promotion->rules()->create([
            'rule_type' => 'product_id',
            'operator' => 'equals',
            'value' => ['value' => $paid->id],
        ]);
        $promotion->actions()->create([
            'action_type' => 'gift_product',
            'configuration' => ['product_id' => $giftProduct->id, 'quantity' => 1],
        ]);
        $engine = app(PromotionEngineService::class);
        $engine->applyAutomaticGifts($cart);

        $cart->items()->paid()->delete();
        $engine->applyAutomaticGifts($cart->fresh());

        $this->assertSame(0, $cart->items()->gifts()->count());
        $this->assertSame(0, $cart->items()->count());
    }

    public function test_checkout_snapshots_and_reduces_paid_and_gift_quantities_separately(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update([
            'price' => 40,
            'regular_price' => 40,
            'promo_price' => null,
            'quantity' => 5,
        ]);
        $cart = $this->cart('checkout-paid-gift');
        $cart->items()->create($this->paidLine($product, 2));
        $this->sameProductGiftPromotion($product);
        app(PromotionEngineService::class)->applyAutomaticGifts($cart);

        $response = $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated();

        $order = Order::query()->findOrFail($response->json('data.id'));
        $lines = $order->items()->where('product_id', $product->id)->orderByDesc('unit_price')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(2, $lines[0]->quantity);
        $this->assertSame(40.0, (float) $lines[0]->unit_price);
        $this->assertSame(1, $lines[1]->quantity);
        $this->assertSame(0.0, (float) $lines[1]->unit_price);
        $this->assertSame(2, $product->fresh()->quantity);
    }

    public function test_insufficient_combined_paid_and_gift_stock_rejects_before_checkout_side_effects(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update([
            'price' => 40,
            'regular_price' => 40,
            'promo_price' => null,
            'quantity' => 2,
        ]);
        $cart = $this->cart('checkout-combined-stock');
        $cart->items()->create($this->paidLine($product, 2));
        $this->sameProductGiftPromotion($product);
        app(PromotionEngineService::class)->applyAutomaticGifts($cart);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertUnprocessable();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(2, $product->fresh()->quantity);
        $this->assertSame(2, $cart->items()->count());
    }

    public function test_abandoned_cart_recovery_preserves_paid_and_gift_identity(): void
    {
        $product = Product::factory()->create(['quantity' => 20, 'price' => 40]);
        $cart = $this->cart('recover-paid-gift');
        $cart->update(['customer_email' => 'recover@example.test']);
        $cart->items()->create($this->paidLine($product, 2));
        $promotion = $this->sameProductGiftPromotion($product);
        app(PromotionEngineService::class)->applyAutomaticGifts($cart);
        $record = app(EmailMarketingService::class)->recordAbandonedCart($cart->fresh());

        $restored = app(EmailMarketingService::class)->restoreCartFromToken(
            $record->recovery_token,
            $this->cartSession('restored-paid-gift'),
        );

        $paid = $restored->items()->paid()->where('product_id', $product->id)->sole();
        $gift = $restored->items()->gifts()->where('product_id', $product->id)->sole();
        $this->assertSame(2, $paid->quantity);
        $this->assertSame(40.0, (float) $paid->unit_price);
        $this->assertSame(1, $gift->quantity);
        $this->assertSame(0.0, (float) $gift->unit_price);
        $this->assertSame($promotion->id, $gift->promotion_id);
    }

    public function test_guest_to_user_merge_regenerates_one_same_product_gift(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['quantity' => 20, 'price' => 40]);
        $target = $this->cart('merge-target');
        $source = $this->cart('merge-source');
        $source->update(['user_id' => $user->id]);
        $target->items()->create($this->paidLine($product, 2));
        $source->items()->create($this->paidLine($product, 3));
        $promotion = $this->sameProductGiftPromotion($product);

        foreach ([$target, $source] as $cart) {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => 9,
                'is_gift' => true,
                'promotion_id' => $promotion->id,
                'unit_price' => 0,
                'total_price' => 0,
            ]);
        }

        $merged = app(CartLifecycleService::class)
            ->resolveAuthenticated($user, $target->session_id);

        $this->assertSame($target->id, $merged->id);
        $this->assertSame(5, $merged->items()->paid()->where('product_id', $product->id)->sole()->quantity);
        $gift = $merged->items()->gifts()->where('product_id', $product->id)->sole();
        $this->assertSame(1, $gift->quantity);
        $this->assertSame($promotion->id, $gift->promotion_id);
        $this->assertSame(0.0, (float) $gift->unit_price);
        $this->assertSame('merged', $source->fresh()->status);
        $this->assertSame(0, $source->items()->count());
    }

    private function qualifyingCart(): array
    {
        $cart = $this->cart('qualifying-cart');
        $paid = Product::factory()->create(['quantity' => 20, 'price' => 100]);
        $gift = Product::factory()->create(['quantity' => 20, 'price' => 25]);
        $cart->items()->create($this->paidLine($paid));

        return [$cart, $paid, $gift];
    }

    private function cart(string $name): Cart
    {
        return Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
    }

    private function paidLine(Product $product, int $quantity = 1): array
    {
        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'is_gift' => false,
            'unit_price' => $product->effectivePrice(),
            'total_price' => $product->effectivePrice() * $quantity,
        ];
    }

    private function promotion(int $priority = 0): Promotion
    {
        return Promotion::query()->create([
            'name' => 'Gift '.fake()->unique()->word(),
            'type' => 'gift_product',
            'status' => 'active',
            'priority' => $priority,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'stackable' => true,
            'stop_further_rules' => false,
        ]);
    }

    private function sameProductGiftPromotion(Product $product): Promotion
    {
        $promotion = $this->promotion(priority: 10);
        $promotion->rules()->create([
            'rule_type' => 'product_id',
            'operator' => 'equals',
            'value' => ['value' => $product->id],
        ]);
        $promotion->actions()->create([
            'action_type' => 'gift_product',
            'configuration' => ['product_id' => $product->id, 'quantity' => 1],
        ]);

        return $promotion;
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'gift-checkout@example.test',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'country' => 'BG',
            'postal_code' => '1000',
            'notes' => null,
            'terms' => true,
        ];
    }
}
