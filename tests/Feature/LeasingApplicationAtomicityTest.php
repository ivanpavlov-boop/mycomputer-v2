<?php

namespace Tests\Feature;

use App\Events\LeasingApplicationSubmitted;
use App\Models\PaymentMethod;
use App\Services\Leasing\LeasingApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class LeasingApplicationAtomicityTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_leasing_application_failure_rolls_back_every_checkout_side_effect(): void
    {
        config()->set('payments.methods.leasing.enabled', true);
        Event::fake([LeasingApplicationSubmitted::class]);
        $cart = $this->prepareCheckoutCart('leasing-atomicity');
        PaymentMethod::query()->where('code', 'leasing')->update(['status' => 'active']);
        $product = $cart->items()->firstOrFail()->product;
        $stockBefore = $product->quantity;

        $service = Mockery::mock(LeasingApplicationService::class)->makePartial();
        $service->shouldReceive('createForOrder')
            ->once()
            ->andThrow(new RuntimeException('Synthetic leasing rollback failure.'));
        $this->app->instance(LeasingApplicationService::class, $service);

        $this->submitCheckout($cart, 'leasing-atomicity', [
            'payment_method' => 'leasing',
            'leasing_application' => [
                'term_months' => 24,
                'down_payment' => '0.00',
                'contact_method' => 'phone',
                'contact_time' => 'anytime',
                'note' => null,
                'consent' => true,
            ],
        ])->assertServerError();

        foreach ([
            'orders',
            'payment_transactions',
            'leasing_applications',
            'leasing_application_activities',
            'order_shipments',
            'checkout_idempotency_records',
            'checkout_confirmation_capabilities',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($stockBefore, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(1, $cart->items()->count());
        Event::assertNotDispatched(LeasingApplicationSubmitted::class);
    }
}
