<?php

namespace Tests\Feature;

use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\ShippingOffice;
use App\Models\ShippingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shipping_providers(): void
    {
        $this->seed();

        $this->getJson('/api/v1/shipping/providers')
            ->assertOk()
            ->assertJsonFragment(['code' => 'speedy'])
            ->assertJsonFragment(['code' => 'econt']);
    }

    public function test_list_shipping_methods(): void
    {
        $this->seed();

        $this->getJson('/api/v1/shipping/methods')
            ->assertOk()
            ->assertJsonFragment(['code' => 'office'])
            ->assertJsonFragment(['code' => 'address']);
    }

    public function test_office_search(): void
    {
        $this->seed();

        $this->getJson('/api/v1/shipping/offices?provider=speedy&city=Sofia')
            ->assertOk()
            ->assertJsonPath('data.0.provider.code', 'speedy');
    }

    public function test_calculate_shipping_price(): void
    {
        $this->seed();

        $this->postJson('/api/v1/shipping/calculate', [
            'provider' => 'speedy',
            'delivery_type' => 'address',
            'shipping_method' => 'address',
            'city' => 'Sofia',
            'address' => 'bul. Bulgaria 1',
        ])->assertOk()
            ->assertJsonPath('data.shipping_price', '8.99')
            ->assertJsonPath('data.provider', 'speedy');
    }

    public function test_checkout_with_office_delivery_creates_shipment(): void
    {
        $office = $this->prepareCartAndOffice();

        $this->withHeader('X-Cart-Session', 'shipping-cart')
            ->postJson('/api/v1/checkout', $this->checkoutPayload([
                'shipping_provider' => 'speedy',
                'shipping_method' => 'office',
                'delivery_type' => 'office',
                'office_id' => $office->id,
                'city' => $office->city,
                'shipping_address' => $office->address,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.shipments.0.delivery_type', 'office');

        $this->assertDatabaseHas('order_shipments', [
            'office_id' => $office->id,
            'delivery_type' => 'office',
        ]);
    }

    public function test_checkout_with_address_delivery_creates_shipment(): void
    {
        $this->prepareCartAndOffice();

        $this->withHeader('X-Cart-Session', 'shipping-cart')
            ->postJson('/api/v1/checkout', $this->checkoutPayload([
                'shipping_provider' => 'econt',
                'shipping_method' => 'address',
                'delivery_type' => 'address',
                'city' => 'Sofia',
                'postcode' => '1000',
                'shipping_address' => 'bul. Bulgaria 1',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.shipments.0.delivery_type', 'address');

        $this->assertSame(1, OrderShipment::query()->count());
    }

    public function test_inactive_provider_cannot_be_used(): void
    {
        $this->seed();
        ShippingProvider::query()->where('code', 'speedy')->update(['status' => 'inactive']);

        $this->postJson('/api/v1/shipping/calculate', [
            'provider' => 'speedy',
            'delivery_type' => 'address',
            'shipping_method' => 'address',
            'city' => 'Sofia',
            'address' => 'Address',
        ])->assertNotFound();
    }

    public function test_invalid_office_cannot_be_used(): void
    {
        $this->seed();
        $econtOffice = ShippingOffice::query()->whereHas('provider', fn ($query) => $query->where('code', 'econt'))->firstOrFail();

        $this->postJson('/api/v1/shipping/calculate', [
            'provider' => 'speedy',
            'delivery_type' => 'office',
            'shipping_method' => 'office',
            'office_id' => $econtOffice->id,
            'city' => 'Sofia',
        ])->assertNotFound();
    }

    private function prepareCartAndOffice(): ShippingOffice
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->withHeader('X-Cart-Session', 'shipping-cart')
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();

        return ShippingOffice::query()->whereHas('provider', fn ($query) => $query->where('code', 'speedy'))->firstOrFail();
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'ship@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'payment_method' => 'cash_on_delivery',
            'shipping_provider' => 'manual',
            'shipping_method' => 'address',
            'delivery_type' => 'address',
            'city' => 'Sofia',
            'terms' => true,
        ], $overrides);
    }
}
