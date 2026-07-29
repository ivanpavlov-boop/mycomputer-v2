<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class LegalAcceptanceCheckoutTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_new_order_stores_server_authoritative_legal_acceptance(): void
    {
        $cart = $this->prepareCheckoutCart('legal-acceptance');

        $this->submitCheckout($cart, 'legal-acceptance')->assertCreated();

        $order = Order::query()->sole();
        $this->assertNotNull($order->legal_accepted_at);
        $this->assertSame('terms-test-1', $order->terms_version);
        $this->assertSame('privacy-test-1', $order->privacy_version);
        $this->assertSame('bg', $order->legal_acceptance_locale);
    }

    public function test_client_cannot_override_legal_versions_timestamp_or_locale(): void
    {
        $cart = $this->prepareCheckoutCart('legal-overrides');

        $response = $this->submitCheckout($cart, 'legal-overrides', [
            'terms_version' => 'attacker-terms',
            'privacy_version' => 'attacker-privacy',
            'legal_accepted_at' => '1999-01-01T00:00:00Z',
            'legal_acceptance_locale' => 'en',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');
        $this->assertEqualsCanonicalizing([
            'terms_version',
            'privacy_version',
            'legal_accepted_at',
            'legal_acceptance_locale',
        ], array_keys($response->json('error.details')));

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_idempotent_replay_preserves_one_acceptance_record(): void
    {
        $cart = $this->prepareCheckoutCart('legal-replay');

        $this->submitCheckout($cart, 'legal-replay')->assertCreated();
        $original = Order::query()->sole();
        $acceptedAt = $original->legal_accepted_at?->toISOString();

        $this->submitCheckout($cart, 'legal-replay')->assertCreated();

        $replayed = Order::query()->sole();
        $this->assertSame($original->getKey(), $replayed->getKey());
        $this->assertSame($acceptedAt, $replayed->legal_accepted_at?->toISOString());
        $this->assertSame('terms-test-1', $replayed->terms_version);
        $this->assertSame('privacy-test-1', $replayed->privacy_version);
        $this->assertSame('bg', $replayed->legal_acceptance_locale);
    }

    public function test_failure_after_order_creation_rolls_back_legal_acceptance_with_order(): void
    {
        $cart = $this->prepareCheckoutCart('legal-rollback');
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('initiate')
            ->once()
            ->andThrow(new RuntimeException('Synthetic rollback failure.'));
        $this->app->instance(PaymentService::class, $mock);

        $this->submitCheckout($cart, 'legal-rollback')->assertServerError();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('checkout_idempotency_records', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('order_shipments', 0);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);
        $this->assertSame('active', $cart->fresh()->status);
    }

    public function test_historical_order_with_null_legal_fields_remains_readable(): void
    {
        $order = Order::query()->create($this->historicalOrderPayload());

        $this->assertNull($order->fresh()->legal_accepted_at);
        $this->assertNull($order->fresh()->terms_version);
        $this->assertNull($order->fresh()->privacy_version);
        $this->assertNull($order->fresh()->legal_acceptance_locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function historicalOrderPayload(): array
    {
        return [
            'order_number' => 'HISTORICAL-LEGAL-NULL',
            'customer_email' => 'historical@example.test',
            'customer_phone' => '0888000000',
            'customer_name' => 'Historical Customer',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 10,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'shipping_method' => 'address_delivery',
            'shipping_status' => 'pending',
            'status' => 'pending',
        ];
    }
}
