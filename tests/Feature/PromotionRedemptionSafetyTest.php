<?php

namespace Tests\Feature;

use App\Exceptions\CartPromotionChangedException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Promotions\PromotionEngineService;
use App\Services\Promotions\PromotionRedemptionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PromotionRedemptionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->product = Product::factory()->create([
            'price' => 100,
            'regular_price' => 100,
            'promo_price' => null,
            'quantity' => 20,
        ]);
    }

    public function test_global_limit_is_consumed_once_and_same_order_is_idempotent(): void
    {
        $cart = $this->cart('global-final-slot');
        $promotion = $this->promotion(usageLimit: 1);
        $result = app(PromotionEngineService::class)->evaluate($cart);
        $order = $this->order();
        $service = app(PromotionRedemptionService::class);

        $first = $service->consume($cart, $order, $result);
        $second = $service->consume($cart, $order, $result);

        $this->assertCount(1, $first);
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame(1, $promotion->fresh()->usage_count);
        $this->assertSame(1, PromotionRedemption::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('marketing_events')->where('event_name', 'promotion_applied')->count());
    }

    public function test_exhausted_global_limit_fails_without_writes(): void
    {
        $cart = $this->cart('exhausted-global');
        $promotion = $this->promotion(usageLimit: 1);
        $result = app(PromotionEngineService::class)->evaluate($cart);
        $promotion->update(['usage_count' => 1]);

        $this->expectException(CartPromotionChangedException::class);

        try {
            app(PromotionRedemptionService::class)->consume($cart, $this->order(), $result);
        } finally {
            $this->assertSame(0, PromotionRedemption::query()->count());
            $this->assertSame(1, $promotion->fresh()->usage_count);
        }
    }

    public function test_per_user_and_per_session_limits_use_cart_identity(): void
    {
        $user = User::factory()->create();
        $userPromotion = $this->promotion();
        $userPromotion->rules()->create([
            'rule_type' => 'per_user_limit',
            'operator' => 'equals',
            'value' => ['value' => 1],
        ]);
        $firstUserCart = $this->cart('same-user-first', $user);
        $secondUserCart = $this->cart('same-user-second', $user);
        $service = app(PromotionRedemptionService::class);

        $service->consume(
            $firstUserCart,
            $this->order(),
            app(PromotionEngineService::class)->evaluate($firstUserCart),
        );

        $this->expectException(CartPromotionChangedException::class);

        try {
            $service->consume(
                $secondUserCart,
                $this->order(),
                [
                    'applied_promotions' => [[
                        'id' => $userPromotion->id,
                        'discount' => 10,
                        'shipping_discount' => 0,
                    ]],
                    'audit' => ['shipping_price' => 0],
                ],
            );
        } finally {
            $this->assertSame(1, $userPromotion->fresh()->usage_count);
            $this->assertSame(1, $userPromotion->redemptions()->count());
        }
    }

    public function test_session_limit_rejects_at_limit_and_anonymous_cart_never_infers_user(): void
    {
        $promotion = $this->promotion();
        $promotion->rules()->create([
            'rule_type' => 'per_session_limit',
            'operator' => 'equals',
            'value' => ['value' => 1],
        ]);
        $cart = $this->cart('same-session');
        $service = app(PromotionRedemptionService::class);
        $result = app(PromotionEngineService::class)->evaluate($cart);

        $service->consume($cart, $this->order(), $result);
        $redemption = $promotion->redemptions()->firstOrFail();

        $this->assertNull($redemption->user_id);
        $this->assertSame($cart->session_id, $redemption->session_id);

        $this->expectException(CartPromotionChangedException::class);
        $service->consume($cart, $this->order(), $result);
    }

    public function test_multiple_promotions_validate_before_any_write_and_lock_in_id_order(): void
    {
        $cart = $this->cart('multi-promotion');
        $first = $this->promotion(priority: 1);
        $second = $this->promotion(priority: 2, usageLimit: 1);
        $result = app(PromotionEngineService::class)->evaluate($cart);
        $second->update(['usage_count' => 1]);

        $this->expectException(CartPromotionChangedException::class);

        try {
            app(PromotionRedemptionService::class)->consume($cart, $this->order(), $result);
        } finally {
            $this->assertSame(0, PromotionRedemption::query()->count());
            $this->assertSame(0, $first->fresh()->usage_count);
            $this->assertSame(1, $second->fresh()->usage_count);
        }
    }

    public function test_outer_rollback_restores_rows_counters_and_after_commit_events(): void
    {
        $cart = $this->cart('rollback');
        $promotion = $this->promotion();
        $result = app(PromotionEngineService::class)->evaluate($cart);
        $order = $this->order();

        try {
            DB::transaction(function () use ($cart, $order, $result): void {
                app(PromotionRedemptionService::class)->consume($cart, $order, $result);
                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force rollback.', $exception->getMessage());
        }

        $this->assertSame(0, PromotionRedemption::query()->count());
        $this->assertSame(0, $promotion->fresh()->usage_count);
        $this->assertSame(0, DB::table('marketing_events')->where('event_name', 'promotion_applied')->count());

        app(PromotionRedemptionService::class)->consume($cart, $order, $result);

        $this->assertSame(1, PromotionRedemption::query()->count());
        $this->assertSame(1, $promotion->fresh()->usage_count);
    }

    public function test_database_identity_rejects_duplicate_non_null_order_but_allows_null_order(): void
    {
        $promotion = $this->promotion();
        $order = $this->order();
        $values = [
            'promotion_id' => $promotion->id,
            'order_id' => $order->id,
            'session_id' => $this->cartSession('unique-redemption'),
            'discount_amount' => 10,
        ];
        PromotionRedemption::query()->create($values);

        try {
            PromotionRedemption::query()->create($values);
            $this->fail('Expected the Promotion/Order unique identity to reject a duplicate.');
        } catch (QueryException) {
            $this->assertSame(1, PromotionRedemption::query()->where('order_id', $order->id)->count());
        }

        PromotionRedemption::query()->create([
            ...$values,
            'order_id' => null,
        ]);
        PromotionRedemption::query()->create([
            ...$values,
            'order_id' => null,
            'session_id' => $this->cartSession('second-null-order'),
        ]);

        $this->assertSame(2, PromotionRedemption::query()->whereNull('order_id')->count());
    }

    private function cart(string $name, ?User $user = null): Cart
    {
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'user_id' => $user?->id,
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
        $cart->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        return $cart->fresh(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']);
    }

    private function promotion(int $priority = 0, ?int $usageLimit = null): Promotion
    {
        $promotion = Promotion::query()->create([
            'name' => 'Safety promotion '.Str::random(8),
            'type' => 'fixed_discount',
            'status' => 'active',
            'priority' => $priority,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'usage_limit' => $usageLimit,
            'usage_count' => 0,
            'stackable' => true,
        ]);
        $promotion->actions()->create([
            'action_type' => 'fixed_discount',
            'configuration' => ['amount' => 10],
        ]);

        return $promotion->fresh(['rules', 'actions']);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'order_number' => 'PROMO-'.Str::upper(Str::random(12)),
            'customer_email' => 'promotion@example.test',
            'customer_phone' => '0888123456',
            'customer_name' => 'Promotion Test',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 0,
            'discount_total' => 10,
            'grand_total' => 90,
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
        ]);
    }
}
