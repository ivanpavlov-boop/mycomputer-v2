<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ErpProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Erp\ErpNet\ErpNetApiClient;
use App\Services\Erp\ErpNet\ErpNetConfig;
use App\Services\Erp\ErpNet\ErpNetCustomerMapper;
use App\Services\Erp\ErpNet\ErpNetOrderMapper;
use App\Services\Erp\ErpService;
use App\Services\Erp\Providers\ErpNetProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpNetProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_erp_net_provider_is_resolved_by_erp_service(): void
    {
        $provider = ErpProvider::query()->create([
            'name' => 'ERP.NET',
            'code' => 'erp_net',
            'status' => 'active',
        ]);

        $this->assertInstanceOf(ErpNetProvider::class, app(ErpService::class)->provider($provider));
    }

    public function test_erp_net_provider_returns_not_configured_response(): void
    {
        $response = (new ErpNetProvider)->testConnection();

        $this->assertFalse($response['success']);
        $this->assertSame('not_configured', $response['status']);
        $this->assertFalse($response['external_calls_enabled']);
        $this->assertStringContainsString('not configured', $response['message']);
    }

    public function test_erp_net_provider_does_not_call_external_services_when_configured(): void
    {
        $config = new ErpNetConfig(
            enabled: true,
            baseUrl: 'https://erp-net.invalid/api',
            apiKey: 'api-key',
        );
        $client = new ErpNetApiClient($config);

        $this->assertFalse($client->externalCallsEnabled());

        $response = (new ErpNetProvider($config, $client))->testConnection();

        $this->assertFalse($response['success']);
        $this->assertSame('unsupported', $response['status']);
        $this->assertStringContainsString('No external request was sent', $response['message']);
    }

    public function test_erp_net_mappers_transform_customer_and_order_payloads(): void
    {
        $customer = Customer::query()->create([
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'ivan@example.com',
            'phone' => '0888123456',
            'company_name' => 'Ivan Computers Ltd',
            'vat_number' => 'BG123456789',
            'billing_address' => 'Sofia billing',
            'shipping_address' => 'Sofia shipping',
        ]);
        $order = $this->order();

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Lenovo ThinkPad',
            'sku' => 'LEN-ERP-001',
            'quantity' => 2,
            'unit_price' => 1000,
            'total_price' => 2000,
        ]);

        $customerPayload = (new ErpNetCustomerMapper)->map($customer);
        $orderPayload = (new ErpNetOrderMapper(new ErpNetConfig(
            companyId: 'COMPANY-1',
            warehouseId: 'WAREHOUSE-1',
            priceListId: 'PRICE-LIST-1',
            vatSettings: ['default_rate' => 20],
        )))->map($order);

        $this->assertSame('company', $customerPayload['party_type']);
        $this->assertSame('Ivan Petrov', $customerPayload['display_name']);
        $this->assertSame('BG123456789', $customerPayload['tax_number']);
        $this->assertSame($order->order_number, $orderPayload['order_number']);
        $this->assertSame('COMPANY-1', $orderPayload['company_id']);
        $this->assertSame('WAREHOUSE-1', $orderPayload['warehouse_id']);
        $this->assertSame('PRICE-LIST-1', $orderPayload['price_list_id']);
        $this->assertSame('LEN-ERP-001', $orderPayload['lines'][0]['sku']);
        $this->assertSame(20, $orderPayload['lines'][0]['vat_rate']);
    }

    public function test_erp_net_provider_responses_do_not_expose_credentials(): void
    {
        $provider = ErpProvider::query()->create([
            'name' => 'ERP.NET',
            'code' => 'erp_net',
            'status' => 'active',
            'credentials' => [
                'api_key' => 'secret-api-key',
            ],
            'settings' => [
                'enabled' => true,
                'base_url' => 'https://erp-net.invalid/api',
                'warehouse_id' => 'WAREHOUSE-1',
            ],
        ]);

        $response = app(ErpService::class)->provider($provider)->testConnection();
        $encoded = json_encode($response);

        $this->assertStringNotContainsString('secret-api-key', $encoded);
        $this->assertTrue($response['config']['api_key_configured']);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD-ERPNET-'.fake()->unique()->numberBetween(1000, 9999),
            'customer_email' => 'erp@example.com',
            'customer_phone' => '0888123456',
            'customer_name' => 'Ivan Petrov',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 2000,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 2000,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'shipping_method' => 'address_delivery',
            'shipping_status' => 'pending',
            'status' => 'pending',
        ]);
    }
}
