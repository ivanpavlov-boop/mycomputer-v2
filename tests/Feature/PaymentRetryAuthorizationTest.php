<?php

namespace Tests\Feature;

use App\Models\CheckoutConfirmationCapability;
use App\Models\User;
use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\TestCase;

class PaymentRetryAuthorizationTest extends TestCase
{
    use BuildsPaymentAttemptFixtures;
    use RefreshDatabase;

    public function test_only_direct_authenticated_order_owner_can_start_attempt(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();
        $other = User::factory()->create(['email' => 'guest@example.test']);
        $order = $this->paymentOrder($owner);
        $this->paymentTransaction($order);

        $this->postJson(
            "/api/v1/account/orders/{$order->id}/payment-attempts",
            [],
            ['Idempotency-Key' => $this->paymentAttemptKey('anonymous')],
        )->assertUnauthorized();

        $this->actingAs($other, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$order->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('foreign')],
            )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'payment_retry_unavailable');

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$order->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('owner')],
            )
            ->assertCreated();

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_email_match_and_staff_role_do_not_bypass_direct_ownership(): void
    {
        $this->enableTestCard();
        $matchingEmail = User::factory()->create(['email' => 'guest@example.test']);
        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        $guestOrder = $this->paymentOrder();
        $this->paymentTransaction($guestOrder);

        foreach ([$matchingEmail, $staff] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->postJson(
                    "/api/v1/account/orders/{$guestOrder->id}/payment-attempts",
                    [],
                    ['Idempotency-Key' => $this->paymentAttemptKey('actor-'.$actor->id)],
                )
                ->assertNotFound();
        }

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_guest_endpoint_accepts_only_retry_capability_and_uses_private_failures(): void
    {
        $this->enableTestCard();
        $order = $this->paymentOrder();
        $this->paymentTransaction($order);
        $confirmation = app(CheckoutConfirmationService::class)->issue($order);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 1);
        $this->assertInstanceOf(
            CheckoutConfirmationCapability::class,
            CheckoutConfirmationCapability::query()->sole(),
        );

        foreach ([null, 'bad', $confirmation] as $token) {
            $request = $this->withHeader(
                'Idempotency-Key',
                $this->paymentAttemptKey('guest-'.($token ?? 'missing')),
            );

            if ($token !== null) {
                $request = $request->withUnencryptedCookie('mc_payment_retry', $token);
            }

            $request->postJson('/api/v1/checkout/payment-attempts')
                ->assertNotFound()
                ->assertJsonPath('error.code', 'payment_retry_unavailable')
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_request_body_is_empty_and_cannot_switch_method_or_order(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner);
        $this->paymentTransaction($order);

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$order->id}/payment-attempts",
                [
                    'order_id' => $order->id,
                    'payment_method' => 'bank_transfer',
                ],
                ['Idempotency-Key' => $this->paymentAttemptKey('body')],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame('card', $order->fresh()->payment_method);
    }
}
