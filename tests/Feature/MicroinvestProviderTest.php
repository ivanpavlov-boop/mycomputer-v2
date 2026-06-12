<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ErpProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Erp\ErpService;
use App\Services\Erp\Microinvest\MicroinvestApiClient;
use App\Services\Erp\Microinvest\MicroinvestConfig;
use App\Services\Erp\Microinvest\MicroinvestCustomerMapper;
use App\Services\Erp\Microinvest\MicroinvestOrderMapper;
use App\Services\Erp\Providers\MicroinvestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MicroinvestProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_microinvest_provider_is_resolved_by_erp_service(): void
    {
        $provider = ErpProvider::query()->create([
            'name' => 'Microinvest',
            'code' => 'microinvest',
            'status' => 'active',
        ]);

        $this->assertInstanceOf(MicroinvestProvider::class, app(ErpService::class)->provider($provider));
    }

    public function test_microinvest_provider_returns_not_configured_response(): void
    {
        $response = (new MicroinvestProvider)->testConnection();

        $this->assertFalse($response['success']);
        $this->assertSame('not_configured', $response['status']);
        $this->assertFalse($response['external_calls_enabled']);
        $this->assertStringContainsString('not configured', $response['message']);
    }

    public function test_microinvest_provider_does_not_call_external_services_when_configured(): void
    {
        $config = new MicroinvestConfig(
            enabled: true,
            baseUrl: 'https://microinvest.invalid/api',
            username: 'api-user',
            password: 'api-password',
        );
        $client = new MicroinvestApiClient($config);

        $this->assertFalse($client->externalCallsEnabled());

        $response = (new MicroinvestProvider($config, $client))->testConnection();

        $this->assertFalse($response['success']);
        $this->assertSame('unsupported', $response['status']);
        $this->assertStringContainsString('No external request was sent', $response['message']);
    }

    public function test_microinvest_mappers_transform_customer_and_order_payloads(): void
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
            'sku' => 'LEN-001',
            'quantity' => 2,
            'unit_price' => 1000,
            'total_price' => 2000,
        ]);

        $customerPayload = (new MicroinvestCustomerMapper)->map($customer);
        $orderPayload = (new MicroinvestOrderMapper(new MicroinvestConfig(
            warehouseCode: 'MAIN',
            vatSettings: ['default_rate' => 20],
        )))->map($order);

        $this->assertSame('company', $customerPayload['customer_type']);
        $this->assertSame('Ivan Petrov', $customerPayload['name']);
        $this->assertSame('BG123456789', $customerPayload['vat_number']);
        $this->assertSame($order->order_number, $orderPayload['order_number']);
        $this->assertSame('MAIN', $orderPayload['warehouse_code']);
        $this->assertSame('LEN-001', $orderPayload['lines'][0]['sku']);
        $this->assertSame(20, $orderPayload['lines'][0]['vat_rate']);
    }

    public function test_microinvest_provider_responses_do_not_expose_credentials(): void
    {
        $provider = ErpProvider::query()->create([
            'name' => 'Microinvest',
            'code' => 'microinvest',
            'status' => 'active',
            'credentials' => [
                'username' => 'secret-user',
                'password' => 'secret-password',
            ],
            'settings' => [
                'enabled' => true,
                'base_url' => 'https://microinvest.invalid/api',
                'warehouse_code' => 'MAIN',
            ],
        ]);

        $response = app(ErpService::class)->provider($provider)->testConnection();
        $encoded = json_encode($response);

        $this->assertStringNotContainsString('secret-user', $encoded);
        $this->assertStringNotContainsString('secret-password', $encoded);
        $this->assertTrue($response['config']['username_configured']);
        $this->assertTrue($response['config']['password_configured']);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD-MICROINVEST-'.fake()->unique()->numberBetween(1000, 9999),
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
