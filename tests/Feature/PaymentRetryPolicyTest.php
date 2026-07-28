<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\Providers\CardPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\TestCase;

class PaymentRetryPolicyTest extends TestCase
{
    use BuildsPaymentAttemptFixtures;
    use RefreshDatabase;

    public function test_failed_and_cancelled_transactions_allow_one_new_attempt(): void
    {
        $fake = $this->enableTestCard();

        foreach (['failed', 'cancelled'] as $status) {
            $owner = User::factory()->create();
            $order = $this->paymentOrder($owner);
            $this->paymentTransaction($order, $status);

            $this->actingAs($owner, 'sanctum')
                ->postJson(
                    "/api/v1/account/orders/{$order->id}/payment-attempts",
                    [],
                    ['Idempotency-Key' => $this->paymentAttemptKey($status)],
                )
                ->assertCreated();

            $this->assertSame('pending', $order->fresh()->payment_status);
        }

        $this->assertSame(2, $fake->initiationCount);
        $this->assertDatabaseCount('payment_attempts', 2);
        $this->assertDatabaseCount('payment_transactions', 4);
    }

    public function test_paid_refunded_and_missing_transactions_fail_closed(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();

        foreach (['paid', 'refunded', null] as $status) {
            $order = $this->paymentOrder(
                $owner,
                paymentStatus: $status ?? 'pending',
            );

            if ($status !== null) {
                $this->paymentTransaction($order, $status);
            }

            $response = $this->actingAs($owner, 'sanctum')
                ->postJson(
                    "/api/v1/account/orders/{$order->id}/payment-attempts",
                    [],
                    ['Idempotency-Key' => $this->paymentAttemptKey('blocked-'.($status ?? 'missing'))],
                )
                ->assertConflict();

            $response->assertJsonPath(
                'error.code',
                $status === 'paid' ? 'payment_already_paid' : 'payment_retry_not_allowed',
            );
        }

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_confirmed_order_and_authorized_transaction_return_expected_results(): void
    {
        $fake = $this->enableTestCard();
        $owner = User::factory()->create();
        $confirmed = $this->paymentOrder($owner, status: 'confirmed');
        $this->paymentTransaction($confirmed, 'failed');
        $authorized = $this->paymentOrder($owner, paymentStatus: 'pending');
        $this->paymentTransaction($authorized, 'authorized');

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$confirmed->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('confirmed')],
            )
            ->assertCreated();
        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$authorized->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('authorized')],
            )
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'authorized')
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, $fake->initiationCount);
        $this->assertDatabaseCount('payment_transactions', 3);
    }

    public function test_inactive_provider_and_non_operational_card_fail_unavailable(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();
        $providerOrder = $this->paymentOrder($owner);
        $this->paymentTransaction($providerOrder);
        $card = PaymentMethod::query()->where('code', 'card')->firstOrFail();
        $card->provider()->update(['status' => 'inactive']);

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$providerOrder->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('provider-inactive')],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'payment_method_unavailable');

        $card->provider()->update(['status' => 'active']);
        $this->app->forgetInstance(CardPaymentProvider::class);
        $nonOperational = $this->paymentOrder($owner);
        $this->paymentTransaction($nonOperational);

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$nonOperational->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('provider-non-operational')],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'payment_method_unavailable');

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_offline_leasing_disabled_and_invalid_order_states_are_not_retryable(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();

        foreach (['cash_on_delivery', 'bank_transfer', 'leasing'] as $method) {
            $order = $this->paymentOrder($owner, method: $method);
            $this->paymentTransaction($order);

            $this->actingAs($owner, 'sanctum')
                ->postJson(
                    "/api/v1/account/orders/{$order->id}/payment-attempts",
                    [],
                    ['Idempotency-Key' => $this->paymentAttemptKey('method-'.$method)],
                )
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'payment_retry_not_supported');
        }

        $cancelled = $this->paymentOrder($owner, status: 'cancelled');
        $this->paymentTransaction($cancelled);
        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$cancelled->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('cancelled-order')],
            )
            ->assertConflict()
            ->assertJsonPath('error.code', 'payment_retry_not_allowed');

        config()->set('payments.methods.card.enabled', false);
        $disabled = $this->paymentOrder($owner);
        $this->paymentTransaction($disabled);
        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$disabled->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('disabled-card')],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'payment_method_unavailable');

        $this->assertDatabaseCount('payment_attempts', 0);
    }
}
