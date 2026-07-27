<?php

namespace Tests\Feature;

use App\Models\CheckoutConfirmationCapability;
use App\Models\Order;
use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class CheckoutConfirmationReplayCapabilityTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_replay_keeps_unexpired_capabilities_and_prunes_only_expired_ones(): void
    {
        $cart = $this->prepareCheckoutCart('capability-pruning');
        $first = $this->submitCheckout($cart, 'capability-pruning')->assertCreated();
        $firstToken = $first->getCookie(CheckoutConfirmationService::COOKIE_NAME, false)?->getValue();
        $order = Order::query()->sole();
        $expired = $order->checkoutConfirmationCapabilities()->create([
            'token_hash' => hash('sha256', 'expired-capability'),
            'expires_at' => now()->subMinute(),
        ]);

        $replay = $this->submitCheckout($cart, 'capability-pruning')->assertCreated();
        $secondToken = $replay->getCookie(CheckoutConfirmationService::COOKIE_NAME, false)?->getValue();

        $this->assertDatabaseMissing('checkout_confirmation_capabilities', ['id' => $expired->id]);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 2);
        $this->assertSame($order->id, app(CheckoutConfirmationService::class)->resolve($firstToken)->order_id);
        $this->assertSame($order->id, app(CheckoutConfirmationService::class)->resolve($secondToken)->order_id);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertSame(
            2,
            CheckoutConfirmationCapability::query()
                ->where('order_id', $order->id)
                ->where('expires_at', '>', now())
                ->count(),
        );
    }

    public function test_confirmation_constraint_rollback_refuses_duplicate_order_capabilities(): void
    {
        $cart = $this->prepareCheckoutCart('capability-rollback');
        $this->submitCheckout($cart, 'capability-rollback')->assertCreated();
        app(CheckoutConfirmationService::class)->issue(Order::query()->sole());
        $migration = require database_path(
            'migrations/2026_07_27_090001_allow_multiple_checkout_confirmation_capabilities.php',
        );

        try {
            $migration->down();
            $this->fail('Rollback must refuse duplicate capabilities for one Order.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Refusing to restore the unique confirmation Order constraint while duplicate capabilities exist.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('checkout_confirmation_capabilities', 2);
    }
}
