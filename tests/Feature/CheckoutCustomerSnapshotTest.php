<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class CheckoutCustomerSnapshotTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_guest_checkout_creates_one_dedicated_customer_snapshot_and_preserves_order_snapshot(): void
    {
        $cart = $this->prepareCheckoutCart('customer-snapshot');
        $payload = [
            'first_name' => 'Elena',
            'last_name' => 'Georgieva',
            'email' => 'elena@example.test',
            'phone' => '0888999000',
            'is_company' => true,
            'company_name' => 'Example Commerce Ltd',
            'vat_number' => 'BG123456789',
            'billing_address' => 'Sofia billing address',
            'shipping_address' => 'Plovdiv shipping address',
            'notes' => 'Do not copy this note to Customer.',
            'payment_method' => 'bank_transfer',
        ];

        $response = $this->submitCheckout($cart, 'customer-snapshot', $payload)
            ->assertCreated()
            ->assertJsonMissingPath('data.customer_id')
            ->assertJsonMissingPath('data.customer');

        $order = Order::query()->sole();
        $customer = Customer::query()->sole();

        $this->assertSame($customer->getKey(), $order->customer_id);
        $this->assertSame($payload['email'], $order->customer_email);
        $this->assertSame($payload['phone'], $order->customer_phone);
        $this->assertSame('Elena Georgieva', $order->customer_name);
        $this->assertSame($payload['company_name'], $order->company_name);
        $this->assertSame($payload['vat_number'], $order->vat_number);
        $this->assertSame($payload['billing_address'], $order->billing_address);
        $this->assertSame($payload['shipping_address'], $order->shipping_address);
        $this->assertSame([
            'first_name',
            'last_name',
            'email',
            'phone',
            'company_name',
            'vat_number',
            'billing_address',
            'shipping_address',
        ], array_values(array_diff(
            array_keys($customer->getAttributes()),
            ['id', 'created_at', 'updated_at'],
        )));
        $this->assertSame($payload['first_name'], $customer->first_name);
        $this->assertSame($payload['last_name'], $customer->last_name);
        $this->assertSame($payload['email'], $customer->email);
        $this->assertSame($payload['phone'], $customer->phone);
        $this->assertSame($payload['company_name'], $customer->company_name);
        $this->assertSame($payload['vat_number'], $customer->vat_number);
        $this->assertSame($payload['billing_address'], $customer->billing_address);
        $this->assertSame($payload['shipping_address'], $customer->shipping_address);
        $this->assertStringNotContainsString('customer_id', $response->getContent());
        $this->assertStringNotContainsString($payload['notes'], json_encode($customer->getAttributes()));

        $orderSnapshot = $order->only([
            'customer_name',
            'customer_email',
            'customer_phone',
            'company_name',
            'vat_number',
            'billing_address',
            'shipping_address',
        ]);
        $customer->update([
            'first_name' => 'Changed',
            'last_name' => 'Customer',
            'email' => 'changed@example.test',
            'phone' => '0000000000',
            'company_name' => 'Changed company',
            'vat_number' => 'CHANGED',
            'billing_address' => 'Changed billing',
            'shipping_address' => 'Changed shipping',
        ]);

        $this->assertSame($orderSnapshot, $order->fresh()->only(array_keys($orderSnapshot)));
    }

    public function test_checkout_never_mutates_existing_customers_by_contact_or_company_fields(): void
    {
        $cart = $this->prepareCheckoutCart('existing-customer-protection');
        $existingCustomers = collect([
            $this->customer(['email' => 'shared@example.test', 'first_name' => 'Existing A']),
            $this->customer(['email' => 'shared@example.test', 'first_name' => 'Existing B']),
            $this->customer(['email' => 'phone@example.test', 'phone' => '0888111222']),
            $this->customer(['email' => 'vat@example.test', 'vat_number' => 'BG999999999']),
            $this->customer(['email' => 'company@example.test', 'company_name' => 'Shared Company']),
        ]);
        $before = $existingCustomers->mapWithKeys(
            fn (Customer $customer): array => [$customer->getKey() => $customer->getAttributes()],
        );

        $this->submitCheckout($cart, 'existing-customer-protection', [
            'first_name' => 'New',
            'last_name' => 'Snapshot',
            'email' => 'shared@example.test',
            'phone' => '0888111222',
            'is_company' => true,
            'company_name' => 'Shared Company',
            'vat_number' => 'BG999999999',
            'billing_address' => 'New billing',
            'shipping_address' => 'New shipping',
        ])->assertCreated();

        foreach ($existingCustomers as $existingCustomer) {
            $beforeAttributes = $before[$existingCustomer->getKey()];
            $afterAttributes = $existingCustomer->fresh()->getAttributes();
            ksort($beforeAttributes);
            ksort($afterAttributes);

            $this->assertSame(
                $beforeAttributes,
                $afterAttributes,
            );
        }

        $order = Order::query()->sole();
        $snapshot = $order->customer()->firstOrFail();

        $this->assertNotContains(
            $snapshot->getKey(),
            $existingCustomers->map->getKey()->all(),
        );
        $this->assertSame(6, Customer::query()->count());
        $this->assertSame('shared@example.test', $snapshot->email);
        $this->assertSame('0888111222', $snapshot->phone);
        $this->assertSame('Shared Company', $snapshot->company_name);
        $this->assertSame('BG999999999', $snapshot->vat_number);
    }

    public function test_authenticated_checkout_preserves_account_profile_addresses_roles_password_and_tokens(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Account',
            'last_name' => 'Owner',
            'name' => 'Account Owner',
            'email' => 'account@example.test',
            'phone' => '0700000000',
            'company_name' => 'Account Company',
            'vat_number' => 'BG111111111',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $cart = $this->prepareCheckoutCart('authenticated-snapshot', user: $user);
        $user->assignRole(User::ROLE_SUPER_ADMIN);
        $profile = $user->profile()->create([
            'avatar' => 'avatars/account-owner.webp',
            'newsletter_subscribed' => true,
            'preferences' => ['locale' => 'bg'],
        ]);
        $address = $user->addresses()->create([
            'type' => 'shipping',
            'first_name' => 'Account',
            'last_name' => 'Owner',
            'company_name' => 'Account Company',
            'vat_number' => 'BG111111111',
            'phone' => '0700000000',
            'country' => 'BG',
            'city' => 'Sofia',
            'postcode' => '1000',
            'address_line_1' => 'Saved address',
            'is_default' => true,
        ]);
        $user->createToken('checkout-snapshot-existing-session');

        $beforeUser = $user->fresh()->getAttributes();
        $beforeProfile = $profile->fresh()->getAttributes();
        $beforeAddress = $address->fresh()->getAttributes();
        $beforeRoles = $user->getRoleNames()->sort()->values()->all();
        $beforeTokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn ($token): array => (array) $token)
            ->all();

        $this->submitCheckout(
            $cart,
            'authenticated-snapshot',
            [
                'first_name' => 'Checkout',
                'last_name' => 'Contact',
                'email' => 'different-checkout@example.test',
                'phone' => '0888000000',
                'is_company' => true,
                'company_name' => 'Checkout Company',
                'vat_number' => 'BG222222222',
                'billing_address' => 'Checkout billing',
                'shipping_address' => 'Checkout shipping',
            ],
            $user,
        )->assertCreated();

        $order = Order::query()->sole();
        $snapshot = Customer::query()->sole();

        $this->assertSame($user->getKey(), $order->user_id);
        $this->assertSame($snapshot->getKey(), $order->customer_id);
        $this->assertSame('different-checkout@example.test', $snapshot->email);
        $this->assertSame($beforeUser, $user->fresh()->getAttributes());
        $this->assertSame($beforeProfile, $profile->fresh()->getAttributes());
        $this->assertSame($beforeAddress, $address->fresh()->getAttributes());
        $this->assertSame($beforeRoles, $user->fresh()->getRoleNames()->sort()->values()->all());
        $this->assertSame(
            $beforeTokens,
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->getKey())
                ->orderBy('id')
                ->get()
                ->map(fn ($token): array => (array) $token)
                ->all(),
        );
    }

    public function test_replays_conflicts_and_unauthorized_attempts_reuse_or_preserve_the_single_snapshot(): void
    {
        $cart = $this->prepareCheckoutCart('snapshot-replay');
        $first = $this->submitCheckout($cart, 'snapshot-replay')->assertCreated();
        $order = Order::query()->sole();
        $customerAttributes = $order->customer()->firstOrFail()->getAttributes();

        $this->submitCheckout($cart, 'snapshot-replay')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));
        $this->submitCheckout($cart, 'snapshot-replay-other')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));
        $this->submitCheckout(
            $cart,
            'snapshot-replay',
            ['shipping_address' => 'Changed replay address'],
        )->assertConflict();

        $unrelatedCart = $this->prepareCheckoutCart('snapshot-replay-unrelated');
        $this->submitCheckout($unrelatedCart, 'snapshot-replay')->assertConflict();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($order->customer_id, Order::query()->sole()->customer_id);
        $this->assertSame(
            $customerAttributes,
            Customer::query()->findOrFail($order->customer_id)->getAttributes(),
        );
    }

    public function test_invalid_checkout_is_rejected_before_customer_snapshot_creation(): void
    {
        $cart = $this->prepareCheckoutCart('invalid-customer-snapshot');

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'checkout_idempotency_key_invalid');

        $this->submitCheckout(
            $cart,
            'invalid-customer-snapshot-card',
            ['payment_method' => 'card'],
        )->assertUnprocessable();

        $invalidPayload = $this->checkoutPayload();
        unset($invalidPayload['email']);
        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('invalid-payload'))
            ->postJson('/api/v1/checkout', $invalidPayload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_idempotency_records', 0);
    }

    private function customer(array $overrides): Customer
    {
        return Customer::query()->create(array_replace([
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'email' => 'existing@example.test',
            'phone' => '0700111222',
            'company_name' => 'Existing Company',
            'vat_number' => 'BG000000000',
            'billing_address' => 'Existing billing',
            'shipping_address' => 'Existing shipping',
        ], $overrides));
    }
}
