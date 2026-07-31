<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ShippingOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class CheckoutBillingModeTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_individual_checkout_derives_billing_snapshot_and_clears_stale_company_data(): void
    {
        $cart = $this->prepareCheckoutCart('individual-billing');
        $payload = $this->checkoutPayload([
            'is_company' => false,
            'company_name' => ['malicious'],
            'vat_number' => ['malicious'],
            'billing_address' => ['malicious'],
            'shipping_address' => 'Sofia, bul. Bulgaria 1',
        ]);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('individual-billing'))
            ->postJson('/api/v1/checkout', $payload)
            ->assertCreated();

        $order = Order::query()->sole();
        $customer = Customer::query()->sole();

        $this->assertNull($order->company_name);
        $this->assertNull($order->vat_number);
        $this->assertSame($payload['shipping_address'], $order->shipping_address);
        $this->assertSame($payload['shipping_address'], $order->billing_address);
        $this->assertNull($customer->company_name);
        $this->assertNull($customer->vat_number);
        $this->assertSame($payload['shipping_address'], $customer->shipping_address);
        $this->assertSame($payload['shipping_address'], $customer->billing_address);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertNotNull($order->legal_accepted_at);
        $this->assertSame('bg', $order->legal_acceptance_locale);
    }

    public function test_individual_checkout_succeeds_without_client_billing_address(): void
    {
        $cart = $this->prepareCheckoutCart('individual-no-billing');
        $payload = $this->checkoutPayload([
            'is_company' => false,
            'shipping_address' => 'Varna, bul. Slivnitsa 10',
        ]);
        unset($payload['billing_address']);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('individual-no-billing'))
            ->postJson('/api/v1/checkout', $payload)
            ->assertCreated();

        $this->assertSame(
            $payload['shipping_address'],
            Order::query()->sole()->billing_address,
        );
    }

    public function test_missing_legacy_company_flag_fails_safe_to_individual_mode(): void
    {
        $cart = $this->prepareCheckoutCart('legacy-individual');
        $payload = $this->checkoutPayload([
            'company_name' => 'Stale Company',
            'vat_number' => 'BG123456789',
            'billing_address' => 'Stale billing address',
            'shipping_address' => 'Trusted shipping address',
        ]);
        unset($payload['is_company']);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('legacy-individual'))
            ->postJson('/api/v1/checkout', $payload)
            ->assertCreated();

        $order = Order::query()->sole();

        $this->assertNull($order->company_name);
        $this->assertNull($order->vat_number);
        $this->assertSame('Trusted shipping address', $order->billing_address);
    }

    public function test_company_checkout_requires_name_and_billing_address_with_bulgarian_messages(): void
    {
        $cart = $this->prepareCheckoutCart('company-validation');

        $this->submitCheckout($cart, 'company-missing-name', [
            'is_company' => true,
            'billing_address' => 'Company billing',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.company_name.0', 'Въведете име на фирмата.');

        $this->submitCheckout($cart, 'company-missing-billing', [
            'is_company' => true,
            'company_name' => 'Example Company',
            'billing_address' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.billing_address.0', 'Въведете адрес за фактуриране.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_valid_company_checkout_preserves_company_order_and_customer_snapshots(): void
    {
        $cart = $this->prepareCheckoutCart('company-billing');

        $this->submitCheckout($cart, 'company-billing', [
            'is_company' => true,
            'company_name' => 'Example Commerce Ltd',
            'vat_number' => 'BG123456789',
            'billing_address' => 'Sofia company billing',
            'shipping_address' => 'Plovdiv shipping',
        ])->assertCreated();

        foreach ([Order::query()->sole(), Customer::query()->sole()] as $snapshot) {
            $this->assertSame('Example Commerce Ltd', $snapshot->company_name);
            $this->assertSame('BG123456789', $snapshot->vat_number);
            $this->assertSame('Sofia company billing', $snapshot->billing_address);
            $this->assertSame('Plovdiv shipping', $snapshot->shipping_address);
        }
    }

    public function test_company_vat_number_remains_nullable(): void
    {
        $cart = $this->prepareCheckoutCart('company-null-vat');

        $this->submitCheckout($cart, 'company-null-vat', [
            'is_company' => true,
            'company_name' => 'Example Commerce Ltd',
            'vat_number' => null,
            'billing_address' => 'Sofia company billing',
        ])->assertCreated();

        $this->assertNull(Order::query()->sole()->vat_number);
        $this->assertNull(Customer::query()->sole()->vat_number);
    }

    public function test_invalid_company_flag_is_rejected_without_checkout_writes(): void
    {
        $cart = $this->prepareCheckoutCart('invalid-company-flag');

        $invalidValues = [
            ['value' => 'company', 'message' => 'Типът на фактуриране е невалиден.'],
            ['value' => 2, 'message' => 'Типът на фактуриране е невалиден.'],
            ['value' => [], 'message' => 'Изберете типа на фактуриране.'],
        ];

        foreach ($invalidValues as $index => $invalid) {
            $this->submitCheckout($cart, 'invalid-company-flag-'.$index, [
                'is_company' => $invalid['value'],
            ])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_error')
                ->assertJsonPath('error.details.is_company.0', $invalid['message']);
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_individual_office_delivery_uses_the_existing_office_address_snapshot(): void
    {
        $cart = $this->prepareCheckoutCart('individual-office');
        $office = ShippingOffice::query()
            ->with('provider')
            ->where('status', 'active')
            ->firstOrFail();
        $shippingAddress = $office->address;
        $payload = $this->checkoutPayload([
            'is_company' => false,
            'shipping_provider' => $office->provider->code,
            'shipping_method' => 'office',
            'delivery_type' => 'office',
            'office_id' => $office->getKey(),
            'city' => $office->city,
            'postcode' => $office->postcode,
            'shipping_address' => $shippingAddress,
        ]);
        unset($payload['billing_address']);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('individual-office'))
            ->postJson('/api/v1/checkout', $payload)
            ->assertCreated();

        $order = Order::query()->sole();

        $this->assertSame($shippingAddress, $order->shipping_address);
        $this->assertSame($shippingAddress, $order->billing_address);
    }

    public function test_duplicate_individual_submit_replays_without_duplicate_order_or_customer(): void
    {
        $cart = $this->prepareCheckoutCart('individual-replay');
        $payload = [
            'is_company' => false,
            'shipping_address' => 'Sofia replay address',
        ];

        $this->submitCheckout($cart, 'individual-replay', $payload)
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false);
        $this->submitCheckout($cart, 'individual-replay', $payload)
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame(0, PaymentAttempt::query()->count());
    }
}
