<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class CheckoutIdempotencyReplayAuthorizationTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_authenticated_owner_can_checkout_and_replay_the_original_order(): void
    {
        $user = User::factory()->create();
        $cart = $this->prepareCheckoutCart('authenticated-owner', user: $user);
        $payload = ['email' => $user->email];
        $first = $this->submitCheckout($cart, 'authenticated-owner', $payload, $user)
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false);

        $this->submitCheckout($cart, 'authenticated-owner', $payload, $user)
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($user->id, Order::query()->sole()->user_id);
    }

    public function test_guest_key_does_not_authorize_an_unrelated_guest_cart(): void
    {
        $original = $this->prepareCheckoutCart('guest-owner');
        $this->submitCheckout($original, 'shared-guest-key')->assertCreated();
        $unrelated = $this->prepareCheckoutCart('unrelated-guest');

        $response = $this->submitCheckout($unrelated, 'shared-guest-key')
            ->assertConflict()
            ->assertJsonPath('error.code', 'checkout_idempotency_conflict');

        $this->assertNull($response->getCookie(CheckoutConfirmationService::COOKIE_NAME, false));
        $response
            ->assertJsonMissingPath('error.order_id')
            ->assertJsonMissingPath('error.order_number');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_authenticated_key_does_not_authorize_an_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $original = $this->prepareCheckoutCart('auth-owner', user: $owner);
        $this->submitCheckout(
            $original,
            'shared-auth-key',
            ['email' => $owner->email],
            $owner,
        )->assertCreated();
        $unrelated = $this->prepareCheckoutCart('auth-attacker', user: $attacker);

        $response = $this->submitCheckout(
            $unrelated,
            'shared-auth-key',
            ['email' => $attacker->email],
            $attacker,
        )->assertConflict();

        $this->assertNull($response->getCookie(CheckoutConfirmationService::COOKIE_NAME, false));
        $response
            ->assertJsonMissingPath('error.order_id')
            ->assertJsonMissingPath('error.order_number');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_guest_completed_cart_with_changed_checkout_fields_returns_conflicts(): void
    {
        $changes = [
            'email' => 'changed@example.test',
            'billing_address' => 'Plovdiv, Bulgaria',
            'shipping_address' => 'Varna, Bulgaria',
            'payment_method' => 'bank_transfer',
            'shipping_method' => 'manual',
            'notes' => 'Changed note',
        ];

        foreach ($changes as $field => $value) {
            $cart = $this->prepareCheckoutCart('changed-'.$field);
            $this->submitCheckout($cart, 'changed-'.$field)->assertCreated();

            $this->submitCheckout(
                $cart,
                'changed-'.$field,
                [$field => $value],
            )
                ->assertConflict()
                ->assertJsonPath('error.code', 'checkout_idempotency_conflict');
        }

        $this->assertDatabaseCount('orders', count($changes));
        $this->assertDatabaseCount('checkout_idempotency_records', count($changes));
    }
}
