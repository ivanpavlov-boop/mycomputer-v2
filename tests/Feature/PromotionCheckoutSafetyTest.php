<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Exceptions\CartPromotionChangedException;
use App\Jobs\ConversionTrackingJob;
use App\Jobs\SendEmailJob;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Services\Promotions\PromotionRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PromotionCheckoutSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotion_conflict_rolls_back_checkout_before_every_side_effect(): void
    {
        config()->set('scout.driver', 'database');
        $this->seed();
        Queue::fake();
        Event::fake([OrderCreated::class]);

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update([
            'price' => 100,
            'regular_price' => 100,
            'promo_price' => null,
            'quantity' => 10,
        ]);
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession('promotion-checkout-conflict'),
            'coupon_code' => 'SAFE10',
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);
        $promotion = Promotion::query()->create([
            'name' => 'Checkout safety',
            'code' => 'SAFE10',
            'type' => 'fixed_discount',
            'status' => 'active',
            'usage_count' => 0,
            'stackable' => true,
        ]);
        $promotion->actions()->create([
            'action_type' => 'fixed_discount',
            'configuration' => ['amount' => 10],
        ]);

        $realService = app(PromotionRedemptionService::class);
        $conflictingService = Mockery::mock(PromotionRedemptionService::class);
        $conflictingService
            ->shouldReceive('consume')
            ->once()
            ->andThrow(new CartPromotionChangedException);
        $conflictingService
            ->shouldReceive('consume')
            ->once()
            ->andReturnUsing(
                fn ($cart, $order, $result) => $realService->consume($cart, $order, $result),
            );
        $this->app->instance(PromotionRedemptionService::class, $conflictingService);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('promotion-conflict'))
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_promotion_changed')
            ->assertJsonPath(
                'error.message',
                'Cart promotions changed. Please review your cart and try again.',
            );

        $this->assertSame(0, Customer::query()->where('email', 'promotion-conflict@example.test')->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, OrderShipment::query()->count());
        $this->assertSame(0, PaymentTransaction::query()->count());
        $this->assertSame(0, PromotionRedemption::query()->count());
        $this->assertSame(0, $promotion->fresh()->usage_count);
        $this->assertSame(10, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame('SAFE10', $cart->fresh()->coupon_code);
        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'cart_id' => $cart->id,
            'quantity' => 1,
        ]);
        Queue::assertNotPushed(ConversionTrackingJob::class);
        Queue::assertNotPushed(SendEmailJob::class);
        Event::assertNotDispatched(OrderCreated::class);
        $this->assertDatabaseMissing('marketing_events', ['event_name' => 'abandoned_cart_recovered']);
        $this->assertDatabaseMissing('marketing_events', ['event_name' => 'promotion_applied']);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('promotion-success'))
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonMissingPath('data.discount_total');

        $this->assertSame(1, PromotionRedemption::query()->count());
        $this->assertSame(1, $promotion->fresh()->usage_count);
        $this->assertSame('10.00', Order::query()->firstOrFail()->discount_total);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('promotion-success'))
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame(1, PromotionRedemption::query()->count());
        $this->assertSame(1, $promotion->fresh()->usage_count);
        $this->assertDatabaseCount('orders', 1);
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'promotion-conflict@example.test',
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
