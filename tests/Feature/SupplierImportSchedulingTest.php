<?php

namespace Tests\Feature;

use App\Jobs\RunSupplierImportJob;
use App\Jobs\SendEmailJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportRun;
use App\Models\User;
use App\Models\XmlMappingTemplate;
use App\Services\Suppliers\SupplierImportNotificationService;
use App\Services\Suppliers\SupplierImportOrchestrator;
use App\Services\Suppliers\SupplierImportSafetyService;
use App\Services\Suppliers\SupplierImportScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierImportSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_schedule_calculates_next_twice_daily_run(): void
    {
        $supplier = $this->supplier([
            'schedule_type' => 'twice_daily',
            'morning_import_time' => '06:00:00',
            'evening_import_time' => '19:00:00',
            'timezone' => 'Europe/Sofia',
        ]);

        $nextRun = app(SupplierImportScheduleService::class)->nextRunAt(
            $supplier,
            now()->setTimezone('Europe/Sofia')->setTime(5, 30)->utc(),
        );

        $this->assertSame('06:00:00', $nextRun->setTimezone('Europe/Sofia')->format('H:i:s'));
    }

    public function test_scheduled_command_dispatches_due_supplier_imports(): void
    {
        Queue::fake([RunSupplierImportJob::class]);

        $supplier = $this->supplier([
            'schedule_enabled' => true,
            'schedule_type' => 'daily',
            'next_import_at' => now()->subMinute(),
        ]);
        $this->feed($supplier);

        $this->artisan('suppliers:run-scheduled-imports')->assertSuccessful();

        Queue::assertPushed(RunSupplierImportJob::class);
        $this->assertDatabaseHas('supplier_import_runs', [
            'supplier_id' => $supplier->id,
            'trigger_type' => 'scheduled',
            'status' => 'pending',
        ]);
    }

    public function test_scheduled_command_skips_disabled_supplier(): void
    {
        Queue::fake([RunSupplierImportJob::class]);

        $this->supplier([
            'import_enabled' => false,
            'schedule_enabled' => true,
            'schedule_type' => 'daily',
            'next_import_at' => now()->subMinute(),
        ]);

        $this->artisan('suppliers:run-scheduled-imports')->assertSuccessful();

        Queue::assertNotPushed(RunSupplierImportJob::class);
    }

    public function test_manual_dispatch_prevents_overlapping_import_runs(): void
    {
        Queue::fake([RunSupplierImportJob::class]);

        $supplier = $this->supplier();
        SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'manual',
            'import_type' => 'xml',
            'status' => 'running',
        ]);

        $run = app(SupplierImportOrchestrator::class)->dispatch($supplier, 'manual');

        $this->assertSame('skipped', $run->status);
        Queue::assertNotPushed(RunSupplierImportJob::class);
    }

    public function test_force_dispatch_bypasses_running_run_guard(): void
    {
        Queue::fake([RunSupplierImportJob::class]);

        $supplier = $this->supplier();
        SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'manual',
            'import_type' => 'xml',
            'status' => 'running',
        ]);

        $run = app(SupplierImportOrchestrator::class)->dispatch($supplier, 'force', true);

        $this->assertSame('pending', $run->status);
        Queue::assertPushed(RunSupplierImportJob::class);
    }

    public function test_empty_feed_protection_fails_import_before_product_sync(): void
    {
        Http::fake([
            'https://feeds.example.com/empty.xml' => Http::response(
                '<products><product><code></code><name></name><price>not-a-number</price></product></products>',
                200,
            ),
        ]);

        $supplier = $this->supplier(['minimum_product_count' => 1]);
        $this->feed($supplier, 'https://feeds.example.com/empty.xml');
        $this->mapping($supplier);

        $run = SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'manual',
            'import_type' => 'xml',
            'status' => 'pending',
        ]);

        $result = app(SupplierImportOrchestrator::class)->execute($run, true);

        $this->assertSame('failed', $result->status);
        $this->assertContains('Empty feed protection triggered: feed returned zero products.', $result->errors);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_csv_supplier_feed_import_stages_supplier_products_before_sync(): void
    {
        Http::fake([
            'https://feeds.example.com/products.csv' => Http::response(
                "sku,name,brand,category,price,quantity\nCSV-1,CSV Product,Lenovo,Laptops,1200,5\n",
                200,
            ),
        ]);

        $supplier = $this->supplier();
        $this->feed($supplier, 'https://feeds.example.com/products.csv', 'csv');

        $run = SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'manual',
            'import_type' => 'csv',
            'status' => 'pending',
        ]);

        $result = app(SupplierImportOrchestrator::class)->execute($run, true);

        $this->assertContains($result->status, ['completed', 'completed_with_warnings']);
        $this->assertDatabaseHas('supplier_products', [
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'CSV-1',
            'name' => 'CSV Product',
            'status' => 'synced',
        ]);
        $this->assertDatabaseHas('products', [
            'sku' => 'CSV-1',
            'name' => 'CSV Product',
        ]);
    }

    public function test_mass_product_drop_blocks_sync_without_destructive_sync(): void
    {
        $supplier = $this->supplier([
            'maximum_product_drop_percent' => 40,
            'allow_destructive_sync' => false,
        ]);
        $previous = SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'scheduled',
            'import_type' => 'xml',
            'status' => 'completed',
            'products_seen' => 100,
            'finished_at' => now()->subDay(),
        ]);
        $current = SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'scheduled',
            'import_type' => 'xml',
            'status' => 'running',
            'products_seen' => 50,
            'finished_at' => now(),
        ]);

        $decision = app(SupplierImportSafetyService::class)->evaluate($supplier, $current);

        $this->assertTrue($decision['block_sync']);
        $this->assertNotEmpty($decision['warnings']);
        $this->assertSame(100, $previous->products_seen);
    }

    public function test_supplier_import_notification_uses_cooldown(): void
    {
        Queue::fake([SendEmailJob::class]);

        $supplier = $this->supplier();
        $run = SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'scheduled',
            'import_type' => 'xml',
            'status' => 'failed',
            'warning_count' => 1,
            'error_count' => 1,
            'warnings' => ['Safety warning'],
            'errors' => ['Safety error'],
        ]);

        app(SupplierImportNotificationService::class)->notifyIfNeeded($supplier, $run);
        app(SupplierImportNotificationService::class)->notifyIfNeeded($supplier->fresh(), $run);

        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_admin_supplier_import_api_requires_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/suppliers/import-runs')->assertForbidden();

        Permission::findOrCreate('view supplier import logs', 'web');
        $user->givePermissionTo('view supplier import logs');

        $this->getJson('/api/v1/admin/suppliers/import-runs')->assertOk();
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'company_name' => 'Test Supplier',
            'slug' => 'test-supplier-'.str()->random(8),
            'status' => 'active',
            'priority' => 10,
            'sync_strategy' => 'lowest_price',
            'import_enabled' => true,
            'schedule_enabled' => true,
            'schedule_type' => 'manual_only',
            'timezone' => 'Europe/Sofia',
            'stagger_minutes' => 20,
            'maximum_product_drop_percent' => 40,
            'minimum_product_count' => 1,
            'allow_destructive_sync' => false,
        ], $overrides));
    }

    private function feed(Supplier $supplier, string $url = 'https://feeds.example.com/products.xml', string $type = 'xml'): SupplierFeed
    {
        return SupplierFeed::query()->create([
            'supplier_id' => $supplier->id,
            'feed_name' => 'Test Feed',
            'feed_type' => $type,
            'feed_url' => $url,
            'update_interval' => 360,
            'status' => 'active',
        ]);
    }

    private function mapping(Supplier $supplier): XmlMappingTemplate
    {
        return XmlMappingTemplate::query()->create([
            'supplier_id' => $supplier->id,
            'name' => 'Test XML Mapping',
            'root_path' => 'products.product',
            'field_map' => [
                'supplier_sku' => 'code',
                'name' => 'name',
                'price' => 'price',
                'quantity' => 'stock',
            ],
            'validation_rules' => [
                'supplier_sku' => 'required',
                'name' => 'required',
                'price' => 'numeric',
            ],
            'defaults' => ['currency' => 'EUR'],
            'is_active' => true,
        ]);
    }
}
