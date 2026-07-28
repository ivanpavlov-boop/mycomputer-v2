<?php

namespace Tests\Feature;

use App\Models\LeasingApplication;
use App\Models\LeasingApplicationActivity;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentRetryCapability;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\PaymentRetryCapabilityService;
use App\Services\Payments\Providers\CardPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\Fakes\FakeCardPaymentProvider;
use Tests\TestCase;

class CommerceCheckoutPaymentAcceptanceTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use BuildsPaymentAttemptFixtures;
    use RefreshDatabase;

    public function test_guest_cod_and_bank_transfer_create_one_canonical_checkout_without_retry(): void
    {
        foreach (['cash_on_delivery', 'bank_transfer'] as $index => $method) {
            $name = 'acceptance-'.$method;
            $cart = $this->prepareCheckoutCart($name);
            $product = $cart->items()->firstOrFail()->product;
            $stockBefore = $product->quantity;
            $checkout = $this->submitCheckout($cart, $name, [
                'payment_method' => $method,
            ])->assertCreated();

            $expectedCount = $index + 1;
            $this->assertDatabaseCount('orders', $expectedCount);
            $this->assertDatabaseCount('payment_transactions', $expectedCount);
            $this->assertDatabaseCount('payment_attempts', 0);
            $this->assertDatabaseCount('payment_retry_capabilities', 0);
            $this->assertDatabaseCount('leasing_applications', 0);
            $this->assertDatabaseCount('order_shipments', $expectedCount);
            $this->assertSame($stockBefore - 1, $product->fresh()->quantity);
            $this->assertSame('converted', $cart->fresh()->status);
            $this->assertSame(
                'none',
                $this->confirmation($checkout)
                    ->assertOk()
                    ->json('data.payment.presentation.action.type'),
            );
        }
    }

    public function test_guest_manual_leasing_is_atomic_and_has_no_payment_retry_action(): void
    {
        config()->set('payments.methods.leasing.enabled', true);
        $cart = $this->prepareCheckoutCart('acceptance-leasing');
        PaymentMethod::query()
            ->where('code', 'leasing')
            ->update(['status' => 'active']);
        $checkout = $this->submitCheckout($cart, 'acceptance-leasing', [
            'payment_method' => 'leasing',
            'leasing_application' => [
                'term_months' => 24,
                'down_payment' => '0.00',
                'contact_method' => 'email',
                'contact_time' => 'morning',
                'note' => null,
                'consent' => true,
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('payment_retry_capabilities', 0);
        $this->assertDatabaseCount('leasing_applications', 1);
        $this->assertDatabaseCount('leasing_application_activities', 1);
        $this->assertSame(
            LeasingApplication::STATUS_SUBMITTED,
            LeasingApplication::query()->sole()->status,
        );
        $this->assertSame(
            LeasingApplicationActivity::EVENT_SUBMITTED,
            LeasingApplicationActivity::query()->sole()->event_type,
        );
        $this->confirmation($checkout)
            ->assertOk()
            ->assertJsonPath(
                'data.payment.presentation.status_label',
                'Заявката е получена',
            )
            ->assertJsonPath('data.payment.presentation.action.type', 'none');
    }

    public function test_guest_fake_card_checkout_replays_without_duplicate_provider_or_order(): void
    {
        $cart = $this->prepareCheckoutCart('acceptance-card');
        $fake = $this->enableSeededTestCard();
        $first = $this->submitCheckout($cart, 'acceptance-card', [
            'payment_method' => 'card',
        ])->assertCreated();
        $sameKey = $this->submitCheckout($cart, 'acceptance-card', [
            'payment_method' => 'card',
        ])->assertCreated();
        $otherKey = $this->submitCheckout($cart, 'acceptance-card-other', [
            'payment_method' => 'card',
        ])->assertCreated();

        $sameKey->assertJsonPath('data.idempotent_replay', true);
        $otherKey->assertJsonPath('data.idempotent_replay', true);
        $this->assertSame($first->json('data.order_number'), $sameKey->json('data.order_number'));
        $this->assertSame($first->json('data.order_number'), $otherKey->json('data.order_number'));
        $this->assertSame(1, $fake->initiationCount);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('payment_retry_capabilities', 3);
        $this->confirmation($first)
            ->assertOk()
            ->assertJsonPath(
                'data.payment.presentation.action.type',
                'continue_payment',
            )
            ->assertJsonPath(
                'data.payment.presentation.redirect_url',
                'https://payments.example.test/continue',
            );
    }

    public function test_authenticated_checkout_uses_direct_ownership_and_issues_no_guest_capability(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $cart = $this->prepareCheckoutCart(
            'acceptance-auth-card',
            user: $user,
        );
        $this->enableSeededTestCard();
        $checkout = $this->submitCheckout(
            $cart,
            'acceptance-auth-card',
            [
                'email' => $user->email,
                'payment_method' => 'card',
            ],
            $user,
        )->assertCreated();
        $order = Order::query()->sole();

        $this->assertSame($user->getKey(), $order->user_id);
        $this->assertDatabaseCount('payment_retry_capabilities', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->confirmation($checkout)
            ->assertOk()
            ->assertJsonPath('data.payment.presentation.action.type', 'continue_payment');

        $order->paymentTransactions()->latest()->firstOrFail()->update([
            'status' => 'failed',
            'failed_at' => now(),
        ]);
        $order->update(['payment_status' => 'failed']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/account/orders/{$order->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.payment.presentation.action.type', 'retry_payment');
    }

    public function test_guest_failed_payment_requires_capability_and_same_attempt_key_replays(): void
    {
        $this->seed();
        $fake = $this->enableSeededTestCard();
        $order = $this->paymentOrder(paymentStatus: 'failed');
        $this->paymentTransaction($order, 'failed');
        $token = app(PaymentRetryCapabilityService::class)
            ->issue($order);
        $this->assertNotNull($token);
        $key = $this->paymentAttemptKey('acceptance-guest-retry');

        $this->postJson(
            '/api/v1/checkout/payment-attempts',
            [],
            ['Idempotency-Key' => $key],
        )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'payment_retry_unavailable');

        $first = $this->withCredentials()
            ->withUnencryptedCookie('mc_payment_retry', $token)
            ->postJson(
                '/api/v1/checkout/payment-attempts',
                [],
                ['Idempotency-Key' => $key],
            )
            ->assertCreated()
            ->assertJsonPath('data.payment.presentation.action.type', 'continue_payment');
        $replay = $this->withCredentials()
            ->withUnencryptedCookie('mc_payment_retry', $token)
            ->postJson(
                '/api/v1/checkout/payment-attempts',
                [],
                ['Idempotency-Key' => $key],
            )
            ->assertOk();

        $this->assertSame($first->json('data.reference'), $replay->json('data.reference'));
        $this->assertSame(1, $fake->initiationCount);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 2);
        $this->assertSame(1, PaymentRetryCapability::query()->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(2, PaymentTransaction::query()->count());
    }

    private function confirmation(TestResponse $checkout): TestResponse
    {
        $cookie = $checkout->getCookie('mc_checkout_confirmation', false);
        $this->assertNotNull($cookie);

        return $this->withCredentials()
            ->withUnencryptedCookie('mc_checkout_confirmation', $cookie->getValue())
            ->getJson('/api/v1/checkout/confirmation');
    }

    private function enableSeededTestCard(): FakeCardPaymentProvider
    {
        config()->set('payments.methods.card.enabled', true);
        config()->set(
            'payments.methods.card.approved_redirect_hosts',
            ['payments.example.test'],
        );
        PaymentMethod::query()
            ->where('code', 'card')
            ->update(['status' => 'active']);

        $fake = new FakeCardPaymentProvider;
        $this->app->instance(CardPaymentProvider::class, $fake);

        return $fake;
    }
}
