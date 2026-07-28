<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\LeasingApplication;
use App\Models\LeasingApplicationActivity;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class LeasingApplicationCheckoutTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.methods.leasing.enabled', true);
    }

    public function test_valid_checkout_creates_one_manual_leasing_application_atomically(): void
    {
        $cart = $this->leasingCart('valid-leasing');
        $product = $cart->items()->firstOrFail()->product;
        $stockBefore = $product->quantity;

        $this->submitLeasingCheckout($cart, 'valid-leasing')->assertCreated();

        $order = Order::query()->sole();
        $transaction = PaymentTransaction::query()->sole();
        $application = LeasingApplication::query()->sole();
        $activity = LeasingApplicationActivity::query()->sole();

        $this->assertSame($order->getKey(), $application->order_id);
        $this->assertSame(LeasingApplication::STATUS_SUBMITTED, $application->status);
        $this->assertSame(24, $application->requested_term_months);
        $this->assertSame('25.00', $application->requested_down_payment);
        $this->assertSame('EUR', $application->currency);
        $this->assertSame('phone', $application->preferred_contact_method);
        $this->assertSame('afternoon', $application->preferred_contact_time);
        $this->assertSame('Свържете се след 14:00 ч.', $application->customer_note);
        $this->assertNotNull($application->contact_consent_at);
        $this->assertStringStartsWith('LA-', $application->reference);
        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->transaction_id);
        $this->assertNull($transaction->raw_response['redirect_url'] ?? null);
        $this->assertSame(
            ['mode' => 'manual_leasing_application'],
            $transaction->raw_response,
        );
        $this->assertSame(LeasingApplicationActivity::EVENT_SUBMITTED, $activity->event_type);
        $this->assertNull($activity->actor_user_id);
        $this->assertSame($stockBefore - 1, $product->fresh()->quantity);
        $this->assertSame('converted', $cart->fresh()->status);
    }

    public function test_leasing_fields_are_validated_in_bulgarian_before_side_effects(): void
    {
        $cart = $this->leasingCart('invalid-leasing');

        $this->submitLeasingCheckout($cart, 'invalid-leasing', [
            'leasing_application' => [
                'term_months' => 7,
                'down_payment' => '-1.123',
                'contact_method' => 'provider',
                'contact_time' => 'midnight',
                'note' => null,
                'consent' => false,
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure([
                'error' => ['details' => [
                    'leasing_application.term_months',
                    'leasing_application.down_payment',
                    'leasing_application.contact_method',
                    'leasing_application.contact_time',
                    'leasing_application.consent',
                ]],
            ]);

        $this->assertCheckoutTablesEmpty();
        $this->assertSame('active', $cart->fresh()->status);
    }

    public function test_down_payment_cannot_exceed_trusted_grand_total_and_creates_no_idempotency_record(): void
    {
        $cart = $this->leasingCart('high-down-payment');

        $response = $this->submitLeasingCheckout($cart, 'high-down-payment', [
            'leasing_application' => $this->leasingPreferences([
                'down_payment' => '999999.00',
            ]),
        ]);
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');
        $this->assertSame(
            ['Желаната първоначална вноска не може да надвишава общата сума на поръчката.'],
            $response->json('error.details')['leasing_application.down_payment'],
        );

        $this->assertCheckoutTablesEmpty();
    }

    public function test_unknown_or_non_leasing_fields_are_rejected_without_mutation(): void
    {
        $cart = $this->leasingCart('unknown-fields');

        $unknownResponse = $this->submitLeasingCheckout($cart, 'unknown-fields', [
            'leasing_application' => $this->leasingPreferences([
                'provider' => 'forbidden',
            ]),
        ]);
        $unknownResponse->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');
        $this->assertSame(
            ['Това поле не е позволено.'],
            $unknownResponse->json('error.details')['leasing_application.provider'],
        );

        $other = $this->prepareCheckoutCart('non-leasing-fields');
        $nonLeasingResponse = $this->submitCheckout($other, 'non-leasing-fields', [
            'leasing_application' => $this->leasingPreferences(),
        ]);
        $nonLeasingResponse->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');
        $this->assertSame(
            ['Данни за покупка на изплащане са позволени само при избран този начин на плащане.'],
            $nonLeasingResponse->json('error.details')['leasing_application'],
        );

        $this->assertCheckoutTablesEmpty();
    }

    public function test_foreign_cart_ownership_precedes_availability_and_field_validation(): void
    {
        config()->set('payments.methods.leasing.enabled', false);
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $cart = $this->prepareCheckoutCart('foreign-leasing-cart', user: $owner);

        $response = $this->submitCheckout($cart, 'foreign-leasing-cart', [
            'payment_method' => 'leasing',
            'leasing_application' => ['provider' => 'forbidden'],
        ], $foreign);

        $response->assertForbidden()
            ->assertJsonMissing(['code' => 'payment_method_unavailable'])
            ->assertJsonMissingValidationErrors('leasing_application.provider');
        $this->assertCheckoutTablesEmpty();
    }

    public function test_checkout_replay_returns_same_application_without_duplicate_activity(): void
    {
        $cart = $this->leasingCart('leasing-replay');
        $first = $this->submitLeasingCheckout($cart, 'leasing-replay')->assertCreated();

        $this->submitLeasingCheckout($cart, 'leasing-replay')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));

        $this->submitLeasingCheckout($cart, 'leasing-replay-other-key')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('leasing_applications', 1);
        $this->assertDatabaseCount('leasing_application_activities', 1);
    }

    public function test_leasing_schema_has_one_application_per_order_without_duplicated_customer_pii(): void
    {
        $columns = Schema::getColumnListing('leasing_applications');

        foreach (['customer_name', 'customer_email', 'customer_phone', 'billing_address', 'shipping_address', 'payload'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $indexes = collect(Schema::getIndexes('leasing_applications'));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['unique'] && $index['columns'] === ['order_id'],
        ));
    }

    private function leasingCart(string $name): Cart
    {
        $cart = $this->prepareCheckoutCart($name);
        PaymentMethod::query()->where('code', 'leasing')->update(['status' => 'active']);

        return $cart;
    }

    private function submitLeasingCheckout(Cart $cart, string $key, array $overrides = [])
    {
        return $this->submitCheckout($cart, $key, array_replace_recursive([
            'payment_method' => 'leasing',
            'leasing_application' => $this->leasingPreferences(),
        ], $overrides));
    }

    private function leasingPreferences(array $overrides = []): array
    {
        return array_replace([
            'term_months' => 24,
            'down_payment' => '25.00',
            'contact_method' => 'phone',
            'contact_time' => 'afternoon',
            'note' => 'Свържете се след 14:00 ч.',
            'consent' => true,
        ], $overrides);
    }

    private function assertCheckoutTablesEmpty(): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('leasing_applications', 0);
        $this->assertDatabaseCount('leasing_application_activities', 0);
        $this->assertDatabaseCount('checkout_idempotency_records', 0);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);
    }
}
