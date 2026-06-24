<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Filament\Resources\CatalogSyncBatches\CatalogSyncBatchResource;
use App\Filament\Resources\CatalogSyncLogs\CatalogSyncLogResource;
use App\Models\CatalogSyncBatch;
use App\Models\CatalogSyncLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSyncAdminVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_sync_preview_renders_effective_feature_flag_statuses(): void
    {
        $this->actingAsCatalogManager();

        $this->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Catalog Sync feature flags')
            ->assertSee('CREATE sync')
            ->assertSee('UPDATE sync')
            ->assertSee('Sync All')
            ->assertSee('Automatic sync')
            ->assertSee('data-catalog-sync-feature-flag="create-sync"', false)
            ->assertSee('data-catalog-sync-feature-flag="update-sync"', false)
            ->assertSee('data-catalog-sync-feature-flag="sync-all"', false)
            ->assertSee('data-catalog-sync-feature-flag="automatic-sync"', false)
            ->assertSeeInOrder(['CREATE sync', 'enabled'])
            ->assertSeeInOrder(['UPDATE sync', 'disabled'])
            ->assertSeeInOrder(['Sync All', 'disabled'])
            ->assertSeeInOrder(['Automatic sync', 'disabled']);
    }

    public function test_catalog_sync_batch_resource_is_read_only_and_visible_to_authorized_admin(): void
    {
        $this->actingAsViewerAuditor();

        $batch = $this->catalogSyncBatch();

        $this->assertTrue(CatalogSyncBatchResource::canViewAny());
        $this->assertTrue(CatalogSyncBatchResource::canView($batch));
        $this->assertFalse(CatalogSyncBatchResource::canCreate());
        $this->assertFalse(CatalogSyncBatchResource::canEdit($batch));
        $this->assertFalse(CatalogSyncBatchResource::canDelete($batch));
        $this->assertFalse(CatalogSyncBatchResource::canDeleteAny());
        $this->assertArrayNotHasKey('create', CatalogSyncBatchResource::getPages());
        $this->assertArrayNotHasKey('edit', CatalogSyncBatchResource::getPages());

        $this->get(CatalogSyncBatchResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Catalog Sync Batches')
            ->assertSee($batch->batch_uuid);

        $this->get(CatalogSyncBatchResource::getUrl('view', ['record' => $batch]))
            ->assertOk()
            ->assertSee($batch->batch_uuid)
            ->assertSee('manual_selected_update_price_stock');
    }

    public function test_catalog_sync_log_resource_is_read_only_and_displays_audit_values(): void
    {
        $this->actingAsViewerAuditor();

        [$batch, $log] = $this->catalogSyncLog();

        $this->assertTrue(CatalogSyncLogResource::canViewAny());
        $this->assertTrue(CatalogSyncLogResource::canView($log));
        $this->assertFalse(CatalogSyncLogResource::canCreate());
        $this->assertFalse(CatalogSyncLogResource::canEdit($log));
        $this->assertFalse(CatalogSyncLogResource::canDelete($log));
        $this->assertFalse(CatalogSyncLogResource::canDeleteAny());
        $this->assertArrayNotHasKey('create', CatalogSyncLogResource::getPages());
        $this->assertArrayNotHasKey('edit', CatalogSyncLogResource::getPages());

        $this->get(CatalogSyncLogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Catalog Sync Logs')
            ->assertSee($batch->batch_uuid)
            ->assertSee('updated_price_stock');

        $this->get(CatalogSyncLogResource::getUrl('view', ['record' => $log]))
            ->assertOk()
            ->assertSee($batch->batch_uuid)
            ->assertSee('Field comparison')
            ->assertSee('Field')
            ->assertSee('Old value')
            ->assertSee('New value')
            ->assertSee('Price')
            ->assertSee('99.99')
            ->assertSee('129.99')
            ->assertSee('Quantity')
            ->assertSee('Final selling price')
            ->assertSee('Supplier cost')
            ->assertSee('Selected supplier offer')
            ->assertSee('Regular price')
            ->assertSee('Metadata summary')
            ->assertSee('Match type')
            ->assertSee('Sync action')
            ->assertSee('Sync reason')
            ->assertSee('Match confidence')
            ->assertSee('Raw old values')
            ->assertSee('Raw new values')
            ->assertSee('Raw metadata')
            ->assertSee('unknown_extra_field')
            ->assertSee('unknown_metadata_key')
            ->assertSee('selected_supplier_offer_id');
    }

    public function test_catalog_sync_admin_visibility_requires_audit_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $batch = $this->catalogSyncBatch();
        [, $log] = $this->catalogSyncLog($batch);

        $this->actingAs($user);

        $this->assertFalse(CatalogSyncBatchResource::canViewAny());
        $this->assertFalse(CatalogSyncLogResource::canViewAny());
        $this->get(CatalogSyncBatchResource::getUrl('index'))->assertForbidden();
        $this->get(CatalogSyncBatchResource::getUrl('view', ['record' => $batch]))->assertForbidden();
        $this->get(CatalogSyncLogResource::getUrl('index'))->assertForbidden();
        $this->get(CatalogSyncLogResource::getUrl('view', ['record' => $log]))->assertForbidden();
    }

    public function test_catalog_sync_admin_visibility_does_not_modify_products_or_supplier_products(): void
    {
        $this->actingAsViewerAuditor();

        $product = Product::factory()->create(['price' => 100, 'quantity' => 4]);
        $supplierProduct = $this->supplierProduct(Supplier::factory()->create(), ['price' => 90, 'quantity' => 7]);
        $batch = $this->catalogSyncBatch();
        $log = CatalogSyncLog::query()->create([
            'catalog_sync_batch_id' => $batch->id,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_product_id' => $supplierProduct->id,
            'product_id' => $product->id,
            'action' => CatalogSyncLog::ACTION_UPDATE,
            'status' => CatalogSyncLog::STATUS_SUCCESS,
            'reason' => 'updated_price_stock',
            'old_values' => ['price' => 100, 'quantity' => 4],
            'new_values' => ['price' => 120, 'quantity' => 7],
            'metadata' => ['visibility_only' => true],
        ]);

        $this->get(CatalogSyncPreview::getUrl())->assertOk();
        $this->get(CatalogSyncBatchResource::getUrl('index'))->assertOk();
        $this->get(CatalogSyncLogResource::getUrl('view', ['record' => $log]))->assertOk();

        $this->assertSame('100.00', $product->fresh()->price);
        $this->assertSame(4, $product->fresh()->quantity);
        $this->assertSame('90.00', $supplierProduct->fresh()->price);
        $this->assertSame(7, $supplierProduct->fresh()->quantity);
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    private function actingAsCatalogManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['role' => User::ROLE_CATALOG_MANAGER]);
        $user->assignRole(User::ROLE_CATALOG_MANAGER);

        $this->actingAs($user);

        return $user;
    }

    private function actingAsViewerAuditor(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['role' => User::ROLE_VIEWER_AUDITOR]);
        $user->assignRole(User::ROLE_VIEWER_AUDITOR);

        $this->actingAs($user);

        return $user;
    }

    private function catalogSyncBatch(?User $user = null, ?Supplier $supplier = null): CatalogSyncBatch
    {
        return CatalogSyncBatch::query()->create([
            'batch_uuid' => fake()->unique()->uuid(),
            'user_id' => $user?->id,
            'supplier_id' => $supplier?->id,
            'mode' => CatalogSyncBatch::MODE_MANUAL_SELECTED_UPDATE_PRICE_STOCK,
            'status' => CatalogSyncBatch::STATUS_COMPLETED,
            'selected_count' => 2,
            'created_count' => 0,
            'updated_count' => 1,
            'skipped_count' => 1,
            'failed_count' => 0,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'metadata' => ['source' => 'catalog_sync_preview', 'update_enabled' => false],
        ]);
    }

    /**
     * @return array{0: CatalogSyncBatch, 1: CatalogSyncLog}
     */
    private function catalogSyncLog(?CatalogSyncBatch $batch = null): array
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $product = Product::factory()->create(['name' => 'Catalog Product']);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Supplier Product',
        ]);
        $batch ??= $this->catalogSyncBatch(supplier: $supplier);

        $log = CatalogSyncLog::query()->create([
            'catalog_sync_batch_id' => $batch->id,
            'supplier_id' => $supplier->id,
            'supplier_product_id' => $supplierProduct->id,
            'product_id' => $product->id,
            'action' => CatalogSyncLog::ACTION_UPDATE,
            'status' => CatalogSyncLog::STATUS_SUCCESS,
            'reason' => 'updated_price_stock',
            'old_values' => [
                'price' => 99.99,
                'final_selling_price' => 99.99,
                'supplier_price_raw' => 80,
                'quantity' => 2,
                'reguar_price' => 99.99,
                'unknown_extra_field' => 'legacy old value',
            ],
            'new_values' => [
                'price' => 129.99,
                'final_selling_price' => 129.99,
                'supplier_price_raw' => 100,
                'quantity' => 2,
                'reguar_price' => 129.99,
                'selected_supplier_offer_id' => 123,
                'unknown_extra_field' => 'legacy new value',
            ],
            'metadata' => [
                'selected_supplier_offer_id' => 123,
                'match_type' => 'ean',
                'sync_action' => 'UPDATE',
                'sync_reason' => 'matched_catalog_product_can_be_updated',
                'match_confidence' => 'exact',
                'unknown_metadata_key' => 'fallback metadata value',
            ],
        ]);

        return [$batch, $log];
    }

    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => fake()->unique()->bothify('SUP-####??'),
            'ean' => fake()->unique()->numerify('#############'),
            'mpn' => fake()->unique()->bothify('MPN-####??'),
            'name' => 'Catalog Sync Admin Visibility Supplier Product',
            'brand_name' => 'Preview Brand',
            'category_name' => 'Preview Category',
            'price' => 100,
            'quantity' => 5,
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => fake()->unique()->sha256(),
            'received_at' => now(),
            'status' => 'active',
        ], $overrides));
    }
}
