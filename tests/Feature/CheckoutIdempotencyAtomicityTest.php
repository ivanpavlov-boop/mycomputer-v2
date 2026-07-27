<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Jobs\ConversionTrackingJob;
use App\Jobs\SendEmailJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\PaymentTransaction;
use App\Services\Orders\CheckoutConfirmationService;
use App\Services\Payments\PaymentService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class CheckoutIdempotencyAtomicityTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_shipment_failure_rolls_back_the_entire_checkout_and_emits_no_effects(): void
    {
        $cart = $this->prepareCheckoutCart('shipment-rollback');
        $this->assertCheckoutFailureRollsBack($cart, ShipmentService::class, 'create');
    }

    public function test_payment_failure_rolls_back_the_entire_checkout_and_emits_no_effects(): void
    {
        $cart = $this->prepareCheckoutCart('payment-rollback');
        $this->assertCheckoutFailureRollsBack($cart, PaymentService::class, 'initiate');
    }

    public function test_confirmation_failure_rolls_back_the_entire_checkout_and_emits_no_effects(): void
    {
        $cart = $this->prepareCheckoutCart('confirmation-rollback');
        $this->assertCheckoutFailureRollsBack($cart, CheckoutConfirmationService::class, 'issue');
    }

    private function assertCheckoutFailureRollsBack(
        $cart,
        string $serviceClass,
        string $method,
    ): void {
        Queue::fake([ConversionTrackingJob::class, SendEmailJob::class]);
        Event::fake([OrderCreated::class]);
        $product = $cart->items()->firstOrFail()->product;
        $stockBefore = $product->quantity;
        $customerCount = Customer::query()->count();

        $mock = Mockery::mock($serviceClass);
        $mock->shouldReceive($method)
            ->once()
            ->andThrow(new RuntimeException('Synthetic checkout rollback failure.'));
        $this->app->instance($serviceClass, $mock);

        $this->submitCheckout($cart, 'rollback-'.$method)->assertServerError();

        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_idempotency_records', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('order_shipments', 0);
        $this->assertDatabaseCount('promotion_redemptions', 0);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);
        $this->assertSame($stockBefore, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, PaymentTransaction::query()->count());
        $this->assertSame(0, OrderShipment::query()->count());
        Queue::assertNothingPushed();
        Event::assertNotDispatched(OrderCreated::class);
    }
}
