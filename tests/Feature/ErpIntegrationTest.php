<?php

namespace Tests\Feature;

use App\Filament\Resources\ErpProviders\ErpProviderResource;
use App\Filament\Resources\ErpSyncJobs\ErpSyncJobResource;
use App\Jobs\CreateErpInvoiceJob;
use App\Jobs\SyncCustomerToErpJob;
use App\Jobs\SyncOrderToErpJob;
use App\Jobs\SyncPaymentToErpJob;
use App\Models\Customer;
use App\Models\ErpProvider;
use App\Models\ErpSyncJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ErpService;
use App\Services\Erp\Providers\MockErpProvider;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ErpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_erp_provider_can_be_created_and_credentials_are_encrypted(): void
    {
        $provider = ErpProvider::query()->create([
            'name' => 'Mock ERP',
            'code' => 'mock',
            'status' => 'active',
            'credentials' => ['api_key' => 'secret-key'],
        ]);

        $this->assertSame('secret-key', $provider->fresh()->credentials['api_key']);
        $this->assertStringNotContainsString('secret-key', (string) $provider->getRawOriginal('credentials'));
    }

    public function test_mock_provider_connection_succeeds(): void
    {
        $response = (new MockErpProvider)->testConnection();

        $this->assertTrue($response['success']);
    }

    public function test_manual_provider_keeps_sync_pending(): void
    {
        $order = $this->order();
        $syncJob = app(ErpService::class)->createSyncJob('push', 'order', $order->id, [], null);

        (new SyncOrderToErpJob($syncJob->id))->handle(app(ErpService::class));

        $syncJob->refresh();
        $this->assertSame('pending', $syncJob->status);
        $this->assertTrue($syncJob->response['manual']);
    }

    public function test_order_created_creates_erp_sync_job_without_active_provider(): void
    {
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->withHeader('X-Cart-Session', 'erp-order-created')
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();

        $this->withHeader('X-Cart-Session', 'erp-order-created')
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated();

        $order = Order::query()->where('customer_email', 'erp@example.com')->firstOrFail();
        $this->assertDatabaseHas('erp_sync_jobs', [
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_customer_sync_job_works_with_mock_provider(): void
    {
        $provider = $this->mockProvider();
        $customer = Customer::query()->create([
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'customer-sync@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
        ]);
        $syncJob = app(ErpService::class)->createSyncJob('push', 'customer', $customer->id, ['model' => 'customer'], $provider);

        (new SyncCustomerToErpJob($syncJob->id))->handle(app(ErpService::class));

        $this->assertDatabaseHas('erp_sync_jobs', [
            'id' => $syncJob->id,
            'status' => 'success',
            'external_id' => 'MOCK-CUST-'.$customer->id,
        ]);
        $this->assertDatabaseHas('erp_customer_mappings', [
            'customer_id' => $customer->id,
            'external_customer_id' => 'MOCK-CUST-'.$customer->id,
        ]);
    }

    public function test_order_sync_job_stores_external_id(): void
    {
        $provider = $this->mockProvider();
        $order = $this->order();
        $syncJob = app(ErpService::class)->createSyncJob('push', 'order', $order->id, [], $provider);

        (new SyncOrderToErpJob($syncJob->id))->handle(app(ErpService::class));

        $this->assertDatabaseHas('erp_sync_jobs', [
            'id' => $syncJob->id,
            'status' => 'success',
            'external_id' => 'MOCK-ORDER-'.$order->order_number,
        ]);
    }

    public function test_failed_provider_marks_sync_failed(): void
    {
        $provider = ErpProvider::query()->create(['name' => 'Microinvest', 'code' => 'microinvest', 'status' => 'active']);
        $order = $this->order();
        $syncJob = app(ErpService::class)->createSyncJob('push', 'order', $order->id, [], $provider);
        $job = new SyncOrderToErpJob($syncJob->id);

        try {
            $job->handle(app(ErpService::class));
        } catch (\Throwable $exception) {
            $job->failed($exception);
        }

        $this->assertDatabaseHas('erp_sync_jobs', [
            'id' => $syncJob->id,
            'status' => 'failed',
        ]);
    }

    public function test_retry_failed_sync_dispatches_matching_job(): void
    {
        Queue::fake();
        $syncJob = ErpSyncJob::query()->create([
            'sync_type' => 'push',
            'entity_type' => 'order',
            'entity_id' => $this->order()->id,
            'status' => 'failed',
        ]);

        ErpSyncJobResource::dispatchRetry($syncJob);

        Queue::assertPushed(SyncOrderToErpJob::class);
        $this->assertSame('pending', $syncJob->fresh()->status);
    }

    public function test_invoice_document_record_created(): void
    {
        $provider = $this->mockProvider();
        $order = $this->order();
        $syncJob = app(ErpService::class)->createSyncJob('push', 'invoice', $order->id, [], $provider);

        (new CreateErpInvoiceJob($syncJob->id))->handle(app(ErpService::class));

        $this->assertDatabaseHas('erp_documents', [
            'order_id' => $order->id,
            'document_type' => 'invoice',
            'document_number' => 'INV-'.$order->order_number,
            'status' => 'created',
        ]);
    }

    public function test_payment_status_change_queues_sync(): void
    {
        Queue::fake();
        $this->mockProvider();
        $order = $this->order();

        app(PaymentService::class)->markPaid($order);

        $this->assertDatabaseHas('erp_sync_jobs', [
            'entity_type' => 'payment',
            'entity_id' => $order->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(SyncPaymentToErpJob::class);
    }

    public function test_admin_permissions_are_enforced(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $customer = User::factory()->create();

        $this->actingAs($admin);
        $this->assertTrue(ErpProviderResource::canViewAny());

        $this->actingAs($customer);
        $this->assertFalse(ErpProviderResource::canViewAny());
    }

    public function test_erp_credentials_are_not_exposed_by_admin_status_api(): void
    {
        $provider = ErpProvider::query()->create([
            'name' => 'Mock ERP',
            'code' => 'mock',
            'status' => 'active',
            'credentials' => ['api_key' => 'super-secret'],
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/erp/status')
            ->assertOk()
            ->assertJsonPath('data.active_provider.id', $provider->id)
            ->assertJsonMissing(['api_key' => 'super-secret'])
            ->assertJsonMissing(['credentials' => ['api_key' => 'super-secret']]);
    }

    private function mockProvider(): ErpProvider
    {
        return ErpProvider::query()->create(['name' => 'Mock ERP', 'code' => 'mock', 'status' => 'active']);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD-ERP-'.fake()->unique()->numberBetween(1000, 9999),
            'customer_email' => 'erp@example.com',
            'customer_phone' => '0888123456',
            'customer_name' => 'Ivan Petrov',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'shipping_method' => 'address_delivery',
            'shipping_status' => 'pending',
            'status' => 'pending',
        ]);
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'erp@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'terms' => true,
        ];
    }
}
