<?php

namespace Tests\Feature;

use App\Filament\Resources\AvailabilityStatuses\AvailabilityStatusResource;
use App\Filament\Resources\AvailabilityStatusMappings\AvailabilityStatusMappingResource;
use App\Models\AvailabilityStatus;
use App\Models\AvailabilityStatusMapping;
use App\Models\CsvImportJob;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Availability\AvailabilityStatusService;
use App\Services\Csv\CsvImportService;
use App\Services\Products\ProductSyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AvailabilityStatusSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_default_statuses_are_seeded_with_display_and_purchase_rules(): void
    {
        $this->assertDatabaseHas('availability_statuses', [
            'code' => 'in_stock',
            'color' => 'green',
            'icon' => 'check',
            'allow_purchase' => true,
            'show_stock_quantity' => true,
        ]);

        $this->assertDatabaseHas('availability_statuses', [
            'code' => 'out_of_stock',
            'color' => 'red',
            'allow_purchase' => false,
        ]);

        $orderedCodes = AvailabilityStatus::query()->active()->ordered()->pluck('code')->take(3)->all();

        $this->assertSame(['in_stock', 'limited_stock', 'incoming'], $orderedCodes);
    }

    public function test_availability_mapper_uses_source_mapping_then_falls_back_to_quantity(): void
    {
        $incoming = AvailabilityStatus::query()->where('code', 'incoming')->firstOrFail();
        $outOfStock = AvailabilityStatus::query()->where('code', 'out_of_stock')->firstOrFail();

        AvailabilityStatusMapping::query()->create([
            'source_type' => 'supplier',
            'source_code' => 'ASBIS',
            'external_status' => 'Delivery 3-5 Days',
            'availability_status_id' => $incoming->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $mapper = app(AvailabilityStatusMapper::class);

        $this->assertSame($incoming->id, $mapper->mapWithFallback('supplier', 'ASBIS', 'Delivery 3-5 Days', 0)?->id);
        $this->assertSame($outOfStock->id, $mapper->mapWithFallback('erp', 'ERP', 'Unknown Status', 0)?->id);
    }

    public function test_service_assigns_automatic_status_and_respects_manual_override(): void
    {
        $service = app(AvailabilityStatusService::class);
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $product->update(['quantity' => 0, 'manual_override' => false]);
        $service->assign($product);

        $this->assertSame('out_of_stock', $product->refresh()->stock_status);

        $preorder = AvailabilityStatus::query()->where('code', 'preorder')->firstOrFail();
        $service->assign($product, $preorder, manual: true);
        $product->update(['quantity' => 0]);
        $service->assign($product->fresh());

        $this->assertSame('preorder', $product->refresh()->stock_status);
        $this->assertTrue($product->manual_override);
    }

    public function test_public_api_returns_and_filters_availability_payload(): void
    {
        $preorder = AvailabilityStatus::query()->where('code', 'preorder')->firstOrFail();
        Product::query()->where('sku', 'MC-LAP-001')->update([
            'availability_status_id' => $preorder->id,
            'stock_status' => 'preorder',
            'availability_message' => 'Очаква се нова доставка.',
        ]);

        $this->getJson('/api/v1/products/lenovo-thinkpad-e16-gen-2')
            ->assertOk()
            ->assertJsonPath('data.availability.code', 'preorder')
            ->assertJsonPath('data.availability.allow_purchase', true)
            ->assertJsonPath('data.availability.message', 'Очаква се нова доставка.');

        $this->getJson('/api/v1/products?availability_status=preorder')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'MC-LAP-001');
    }

    public function test_csv_import_can_map_direct_availability_status_code(): void
    {
        $job = $this->importJob('stock', "sku,quantity,availability_status\nMC-LAP-001,0,preorder\n", 'update-only');

        app(CsvImportService::class)->process($job);

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->assertSame('preorder', $product->stock_status);
        $this->assertSame('preorder', $product->availabilityStatus?->code);
    }

    public function test_product_sync_maps_supplier_availability_status(): void
    {
        $supplier = Supplier::query()->where('slug', 'demo-distribution')->firstOrFail();
        $incoming = AvailabilityStatus::query()->where('code', 'incoming')->firstOrFail();

        AvailabilityStatusMapping::query()->create([
            'source_type' => 'supplier',
            'source_code' => $supplier->company_name,
            'external_status' => 'Incoming Shipment',
            'availability_status_id' => $incoming->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $supplierProduct = SupplierProduct::query()
            ->where('supplier_sku', 'SUP-MC-LAP-001')
            ->firstOrFail();
        $supplierProduct->update([
            'external_availability_status' => 'Incoming Shipment',
            'external_availability_label' => 'Incoming Shipment',
        ]);

        app(ProductSyncService::class)->sync($supplierProduct->fresh());

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->assertSame('incoming', $product->stock_status);
        $this->assertSame($incoming->id, $product->availability_status_id);
        $this->assertSame('Incoming Shipment', $product->external_availability_status);
    }

    public function test_unavailable_status_blocks_cart_add_even_with_quantity(): void
    {
        $outOfStock = AvailabilityStatus::query()->where('code', 'out_of_stock')->firstOrFail();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update([
            'quantity' => 10,
            'availability_status_id' => $outOfStock->id,
            'stock_status' => 'out_of_stock',
        ]);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], ['X-Cart-Session' => 'availability-cart'])
            ->assertStatus(422);
    }

    public function test_preorder_status_can_checkout_without_stock_reservation(): void
    {
        $preorder = AvailabilityStatus::query()->where('code', 'preorder')->firstOrFail();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update([
            'quantity' => 0,
            'availability_status_id' => $preorder->id,
            'stock_status' => 'preorder',
        ]);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], ['X-Cart-Session' => 'preorder-cart'])
            ->assertOk()
            ->assertJsonPath('data.items.0.product.availability.code', 'preorder');
    }

    public function test_stock_alert_signup_logs_availability_analytics_event(): void
    {
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->postJson("/api/v1/products/{$product->id}/stock-alerts", [
            'email' => 'stock-alert@example.com',
        ], ['X-Session-Id' => 'marketing-session'])
            ->assertCreated();

        $this->assertDatabaseHas('marketing_events', [
            'event_name' => 'stock_alert_signup',
            'source' => 'internal',
            'session_id' => 'marketing-session',
        ]);
    }

    public function test_search_index_contains_availability_fields(): void
    {
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $searchable = $product->toSearchableArray();

        $this->assertSame('in_stock', $searchable['availability_status_code']);
        $this->assertSame('In Stock', $searchable['availability_status_name']);
        $this->assertTrue($searchable['allow_purchase']);
    }

    public function test_filament_availability_resources_require_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create();
        $support = User::factory()->create();
        $manager->assignRole('manager');
        $support->assignRole('support');

        $this->actingAs($manager);
        $this->assertTrue(AvailabilityStatusResource::canViewAny());
        $this->assertTrue(AvailabilityStatusMappingResource::canViewAny());

        $this->actingAs($support);
        $this->assertFalse(AvailabilityStatusResource::canViewAny());
        $this->assertFalse(AvailabilityStatusMappingResource::canViewAny());
    }

    private function importJob(string $type, string $contents, string $mode = 'create-or-update'): CsvImportJob
    {
        File::ensureDirectoryExists(storage_path('app/imports'));
        $path = 'imports/test-availability-'.uniqid().'.csv';
        file_put_contents(storage_path('app/'.$path), $contents);

        return CsvImportJob::query()->create([
            'type' => $type,
            'status' => 'pending',
            'file_path' => $path,
            'original_filename' => basename($path),
            'mode' => $mode,
        ]);
    }
}
