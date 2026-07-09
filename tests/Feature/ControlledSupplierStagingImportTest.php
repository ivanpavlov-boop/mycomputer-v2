<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupplierImportRunJob;
use App\Jobs\ProcessXmlSupplierFeed;
use App\Jobs\RunSupplierImportJob;
use App\Jobs\SyncProductJob;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\ControlledSupplierStagingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Tests\TestCase;

class ControlledSupplierStagingImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_with_controlled_apply_options(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:controlled-staging-import', $commands);
        $definition = $commands['suppliers:controlled-staging-import']->getDefinition();

        foreach ([
            'supplier',
            'source',
            'fixture',
            'source-type',
            'limit',
            'max-rows',
            'format',
            'dry-run',
            'apply',
            'confirm-supplier',
            'skip-invalid-rows',
            'strict',
            'show-raw-fields',
            'show-normalized',
            'show-identifiers',
            'show-categories',
            'show-issues',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option), 'Missing option '.$option);
        }
    }

    public function test_required_options_remote_sources_and_apply_confirmation_are_guarded(): void
    {
        Http::fake();

        $this->assertSame(1, Artisan::call('suppliers:controlled-staging-import', [
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.xml',
        ]));
        $this->assertStringContainsString('The --supplier option is required.', Artisan::output());

        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $this->assertSame(1, Artisan::call('suppliers:controlled-staging-import', [
            '--supplier' => 'asbis',
        ]));
        $this->assertStringContainsString('The --source or --fixture option is required.', Artisan::output());

        $this->assertSame(1, Artisan::call('suppliers:controlled-staging-import', [
            '--supplier' => 'asbis',
            '--source' => 'https://example.com/feed.xml?token=SHOULD_NOT_APPEAR',
            '--source-type' => 'xml',
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('Remote feed fetching is disabled for controlled staging import. Provide a local file path.', $output);
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', $output);

        $this->assertSame(1, Artisan::call('suppliers:controlled-staging-import', [
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.xml',
            '--apply' => true,
        ]));
        $this->assertStringContainsString('Apply requires --confirm-supplier=asbis.', Artisan::output());

        $this->assertSame(1, Artisan::call('suppliers:controlled-staging-import', [
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.xml',
            '--apply' => true,
            '--confirm-supplier' => 'apcom',
        ]));
        $this->assertStringContainsString('Apply requires --confirm-supplier=asbis.', Artisan::output());

        $other = Supplier::factory()->create(['company_name' => 'Other Supplier', 'slug' => 'other-supplier']);

        $this->assertSame(1, Artisan::call('suppliers:controlled-staging-import', [
            '--supplier' => $other->slug,
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.xml',
            '--apply' => true,
            '--confirm-supplier' => 'asbis',
        ]));
        $this->assertStringContainsString('Apply is currently allowed only for ASBIS.', Artisan::output());

        Http::assertNothingSent();
    }

    /**
     * @throws JsonException
     */
    public function test_dry_run_reports_create_update_skipped_duplicates_and_overlaps_without_writes(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        $asbis = Supplier::factory()->create([
            'company_name' => 'ASBIS',
            'slug' => 'asbis',
            'import_enabled' => true,
            'schedule_enabled' => false,
        ]);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier', 'slug' => 'other-supplier']);

        $this->supplierProduct($asbis, [
            'supplier_sku' => 'ASBIS-LAP-001',
            'ean' => '5901000000001',
            'mpn' => 'ASBIS-MPN-001',
            'price' => 799.00,
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-OVERLAP',
            'ean' => '5909999999999',
            'mpn' => 'SHARED-MPN-001',
            'brand_name' => 'SharedBrand',
        ]);

        $counts = $this->protectedCounts();

        $payload = $this->commandJson([
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.xml',
            '--source-type' => 'xml',
            '--format' => 'json',
            '--limit' => 20,
            '--show-issues' => true,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('asbis', $payload['supplier']['key']);
        $this->assertSame(7, $payload['summary']['rows_scanned']);
        $this->assertSame(3, $payload['summary']['rows_valid']);
        $this->assertSame(4, $payload['summary']['rows_skipped']);
        $this->assertSame(2, $payload['summary']['would_create']);
        $this->assertSame(1, $payload['summary']['would_update']);
        $this->assertGreaterThanOrEqual(2, $payload['summary']['duplicate_rows']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['cross_supplier_matches']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, $payload['records_changed']['catalog_sync']);
        $this->assertTrue(collect($payload['preview_rows'])->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-MPN-ONLY-001' && $row['needs_manual_review']));
        $this->assertSame($counts, $this->protectedCounts());

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /**
     * @throws JsonException
     */
    public function test_apply_creates_and_updates_asbis_supplier_products_only_and_is_idempotent(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        $asbis = Supplier::factory()->create([
            'company_name' => 'ASBIS',
            'slug' => 'asbis',
            'import_enabled' => true,
            'schedule_enabled' => false,
        ]);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier', 'slug' => 'other-supplier']);

        $existingAsbis = $this->supplierProduct($asbis, [
            'supplier_sku' => 'ASBIS-LAP-001',
            'ean' => 'OLD-EAN',
            'mpn' => 'OLD-MPN',
            'name' => 'Old ASBIS laptop',
            'price' => 799.00,
            'quantity' => 1,
        ]);
        $otherSameSku = $this->supplierProduct($other, [
            'supplier_sku' => 'ASBIS-LAP-001',
            'ean' => 'OTHER-EAN',
            'mpn' => 'OTHER-MPN',
            'price' => 1.00,
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-OVERLAP',
            'ean' => '5909999999999',
            'mpn' => 'SHARED-MPN-001',
            'brand_name' => 'SharedBrand',
        ]);

        $counts = $this->protectedCounts();

        $payload = $this->commandJson([
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.xml',
            '--source-type' => 'xml',
            '--format' => 'json',
            '--apply' => true,
            '--confirm-supplier' => 'asbis',
            '--limit' => 20,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('apply', $payload['mode']);
        $this->assertSame(2, $payload['summary']['created']);
        $this->assertSame(1, $payload['summary']['updated']);
        $this->assertSame(3, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, $payload['records_changed']['products']);
        $this->assertSame(0, $payload['records_changed']['catalog_sync']);

        $existingAsbis->refresh();
        $otherSameSku->refresh();

        $this->assertSame('ASBIS TestBook 14 Laptop', $existingAsbis->name);
        $this->assertSame('5901000000001', $existingAsbis->ean);
        $this->assertSame('999.99', $existingAsbis->price);
        $this->assertSame(12, $existingAsbis->quantity);
        $this->assertSame('OTHER-EAN', $otherSameSku->ean);
        $this->assertSame('1.00', $otherSameSku->price);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_id' => $asbis->id,
            'supplier_sku' => 'ASBIS-MPN-ONLY-001',
            'ean' => null,
            'mpn' => 'ASBIS-MPN-ONLY-001',
        ]);
        $this->assertDatabaseHas('supplier_products', [
            'supplier_id' => $asbis->id,
            'supplier_sku' => 'ASBIS-OVERLAP-001',
        ]);
        $this->assertDatabaseMissing('supplier_products', [
            'supplier_id' => $asbis->id,
            'supplier_sku' => 'ASBIS-DUP-001',
        ]);
        $this->assertDatabaseMissing('supplier_products', [
            'supplier_id' => $asbis->id,
            'supplier_sku' => 'ASBIS-MISSING-COMMERCIAL-001',
        ]);

        $asbis->refresh();
        $this->assertTrue((bool) $asbis->import_enabled);
        $this->assertFalse((bool) $asbis->schedule_enabled);

        $afterCounts = $this->protectedCounts();
        $this->assertSame($counts['products'], $afterCounts['products']);
        $this->assertSame($counts['categories'], $afterCounts['categories']);
        $this->assertSame($counts['suppliers'], $afterCounts['suppliers']);
        $this->assertSame($counts['supplier_category_mappings'], $afterCounts['supplier_category_mappings']);
        $this->assertSame($counts['canonical_product_families'], $afterCounts['canonical_product_families']);
        $this->assertSame($counts['category_product_attributes'], $afterCounts['category_product_attributes']);
        $this->assertSame($counts['product_attributes'], $afterCounts['product_attributes']);
        $this->assertSame($counts['attribute_values'], $afterCounts['attribute_values']);
        $this->assertSame($counts['product_attribute_values'], $afterCounts['product_attribute_values']);
        $this->assertSame($counts['catalog_sync_batches'], $afterCounts['catalog_sync_batches']);
        $this->assertSame($counts['catalog_sync_logs'], $afterCounts['catalog_sync_logs']);

        $second = $this->commandJson([
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.xml',
            '--source-type' => 'xml',
            '--format' => 'json',
            '--apply' => true,
            '--confirm-supplier' => 'asbis',
            '--limit' => 20,
        ]);

        $this->assertSame(0, $second['summary']['created']);
        $this->assertSame(3, $second['summary']['updated']);
        $this->assertSame($afterCounts['supplier_products'], SupplierProduct::query()->count());

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNotDispatched(RunSupplierImportJob::class);
        Bus::assertNotDispatched(ProcessSupplierImportRunJob::class);
        Bus::assertNotDispatched(ProcessXmlSupplierFeed::class);
        Bus::assertNotDispatched(SyncProductJob::class);
    }

    /**
     * @throws JsonException
     */
    public function test_apply_rolls_back_supplier_products_on_failure(): void
    {
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);
        $counts = $this->protectedCounts();

        $this->app->bind(ControlledSupplierStagingImportService::class, fn (): ControlledSupplierStagingImportService => new class extends ControlledSupplierStagingImportService
        {
            protected function applyRows(Collection $rows, Supplier $supplier): array
            {
                parent::applyRows($rows, $supplier);

                throw new RuntimeException('Simulated controlled staging import failure.');
            }
        });

        $payload = $this->commandJson([
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.json',
            '--source-type' => 'json',
            '--format' => 'json',
            '--apply' => true,
            '--confirm-supplier' => 'asbis',
        ], expectedStatus: 1);

        $this->assertFalse($payload['success']);
        $this->assertSame('failed_rolled_back', $payload['summary']['safety_status']);
        $this->assertSame($counts, $this->protectedCounts());
        $this->assertSame(0, SupplierProduct::query()->where('supplier_id', $asbis->id)->count());
    }

    /**
     * @throws JsonException
     */
    public function test_csv_and_json_sources_are_supported(): void
    {
        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $csv = $this->commandJson([
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.csv',
            '--source-type' => 'csv',
            '--format' => 'json',
            '--limit' => 20,
        ]);

        $this->assertTrue($csv['success']);
        $this->assertSame('csv', $csv['summary']['source_type']);
        $this->assertSame(7, $csv['summary']['rows_scanned']);
        $this->assertSame(3, $csv['summary']['rows_valid']);

        $json = $this->commandJson([
            '--supplier' => 'asbis',
            '--fixture' => 'tests/Fixtures/Suppliers/asbis_staging_import.json',
            '--source-type' => 'json',
            '--format' => 'json',
            '--apply' => true,
            '--confirm-supplier' => 'asbis',
        ]);

        $this->assertTrue($json['success']);
        $this->assertSame('json', $json['summary']['source_type']);
        $this->assertSame(1, $json['summary']['created']);
        $this->assertDatabaseHas('supplier_products', [
            'supplier_sku' => 'ASBIS-JSON-001',
            'name' => 'ASBIS JSON Product',
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function commandJson(array $arguments, int $expectedStatus = 0): array
    {
        $this->assertSame($expectedStatus, Artisan::call('suppliers:controlled-staging-import', $arguments));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-'.str()->random(8),
            'ean' => null,
            'mpn' => null,
            'name' => 'Supplier staged product',
            'brand_name' => null,
            'category_name' => null,
            'price' => null,
            'quantity' => null,
            'currency' => 'EUR',
            'raw_data' => ['source' => 'test'],
            'payload_hash' => sha1((string) str()->uuid()),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }

    /**
     * @return array<string, int>
     */
    private function protectedCounts(): array
    {
        return [
            'products' => DB::table('products')->count(),
            'supplier_products' => DB::table('supplier_products')->count(),
            'categories' => DB::table('categories')->count(),
            'suppliers' => DB::table('suppliers')->count(),
            'supplier_category_mappings' => DB::table('supplier_category_mappings')->count(),
            'canonical_product_families' => DB::table('canonical_product_families')->count(),
            'category_product_attributes' => DB::table('category_product_attributes')->count(),
            'product_attributes' => DB::table('product_attributes')->count(),
            'attribute_values' => DB::table('attribute_values')->count(),
            'product_attribute_values' => DB::table('product_attribute_values')->count(),
            'catalog_sync_batches' => $this->tableCount('catalog_sync_batches'),
            'catalog_sync_logs' => $this->tableCount('catalog_sync_logs'),
        ];
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }
}
