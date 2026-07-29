<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Jobs\ConversionTrackingJob;
use App\Jobs\SendEmailJob;
use App\Models\Customer;
use App\Services\Orders\CheckoutCustomerSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class CheckoutCustomerSnapshotAtomicityTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_failure_after_customer_snapshot_creation_rolls_back_the_entire_checkout(): void
    {
        Queue::fake([ConversionTrackingJob::class, SendEmailJob::class]);
        Event::fake([OrderCreated::class]);
        Mail::fake();
        Notification::fake();
        $cart = $this->prepareCheckoutCart('customer-snapshot-rollback');
        $product = $cart->items()->firstOrFail()->product;
        $stockBefore = $product->quantity;
        $realService = app(CheckoutCustomerSnapshotService::class);
        $mock = Mockery::mock(CheckoutCustomerSnapshotService::class);
        $mock->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $data) use ($realService): never {
                $realService->create($data);

                throw new RuntimeException('Synthetic failure after Customer snapshot creation.');
            });
        $this->app->instance(CheckoutCustomerSnapshotService::class, $mock);

        $this->submitCheckout($cart, 'customer-snapshot-rollback')->assertServerError();

        $this->assertSame(0, Customer::query()->count());
        foreach ([
            'orders',
            'order_items',
            'checkout_idempotency_records',
            'payment_transactions',
            'payment_retry_capabilities',
            'order_shipments',
            'leasing_applications',
            'promotion_redemptions',
            'checkout_confirmation_capabilities',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($stockBefore, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(1, $cart->items()->count());
        Queue::assertNothingPushed();
        Event::assertNotDispatched(OrderCreated::class);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }
}
