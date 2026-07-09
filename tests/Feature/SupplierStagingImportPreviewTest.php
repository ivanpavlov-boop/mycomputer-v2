<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupplierImportRunJob;
use App\Jobs\ProcessXmlSupplierFeed;
use App\Jobs\RunSupplierImportJob;
use App\Jobs\SyncProductJob;
use App\Models\AttributeValue;
use App\Models\CanonicalProductFamily;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductSyncLog;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JsonException;
use Tests\TestCase;

class SupplierStagingImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:preview-staging-import', $commands);
        $this->assertFalse($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('apply'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('supplier'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('source'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('source-type'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('limit'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('format'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('show-raw-fields'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('show-normalized'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('show-identifiers'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('show-categories'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('show-issues'));
        $this->assertTrue($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('fixture'));
    }

    public function test_default_fixture_preview_is_read_only_and_mutates_nothing(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        $counts = $this->protectedCounts();

        $this->assertSame(0, Artisan::call('suppliers:preview-staging-import'));
        $output = Artisan::output();

        $this->assertStringContainsString('Supplier staging import preview', $output);
        $this->assertStringContainsString('Read-only. No supplier_products writes, imports, feed fetches, queue jobs, Catalog Sync, or catalog writes were run.', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('catalog_sync changed: 0', $output);
        $this->assertSame($counts, $this->protectedCounts());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /**
     * @throws JsonException
     */
    public function test_json_output_reports_xml_preview_sections_and_detected_fields(): void
    {
        $payload = $this->commandJson('suppliers:preview-staging-import', [
            '--fixture' => 'tests/Fixtures/Suppliers/next_supplier_preview.xml',
            '--source-type' => 'xml',
            '--format' => 'json',
            '--limit' => 10,
            '--show-raw-fields' => true,
            '--show-identifiers' => true,
            '--show-categories' => true,
            '--show-issues' => true,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('xml', $payload['summary']['source_type']);
        $this->assertSame(4, $payload['summary']['rows_scanned']);
        $this->assertSame(4, $payload['summary']['rows_returned']);
        $this->assertSame('sku', $payload['detected_fields']['normalized_field_map']['supplier_sku']);
        $this->assertSame('ean', $payload['detected_fields']['normalized_field_map']['ean_gtin']);
        $this->assertSame('mpn', $payload['detected_fields']['normalized_field_map']['mpn']);
        $this->assertSame('brand', $payload['detected_fields']['normalized_field_map']['brand']);
        $this->assertSame('category', $payload['detected_fields']['normalized_field_map']['category']);
        $this->assertSame('price', $payload['detected_fields']['normalized_field_map']['price']);
        $this->assertSame('stock', $payload['detected_fields']['normalized_field_map']['stock']);
        $this->assertSame('availability', $payload['detected_fields']['normalized_field_map']['availability']);
        $this->assertSame(4, $payload['identifier_summary']['supplier_sku_present']);
        $this->assertSame(1, $payload['identifier_summary']['ean_gtin_missing']);
        $this->assertSame(1, $payload['identifier_summary']['duplicate_supplier_sku_in_preview']);
        $this->assertSame(1, $payload['identifier_summary']['duplicate_ean_gtin_in_preview']);
        $this->assertSame(1, $payload['identifier_summary']['duplicate_mpn_in_preview']);
        $this->assertContains('duplicate_ean_gtin_in_preview', $payload['preview_rows'][1]['issues']);
        $this->assertSame(2, $payload['category_summary']['distinct_categories_count']);
        $this->assertSame(3, $payload['price_stock_summary']['price_present']);
        $this->assertSame(1, $payload['price_stock_summary']['price_missing']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertArrayHasKey('raw_field_names', $payload['preview_rows'][0]);
    }

    /**
     * @throws JsonException
     */
    public function test_csv_fixture_preview_detects_identifiers_category_price_stock_and_issues(): void
    {
        $payload = $this->commandJson('suppliers:preview-staging-import', [
            '--fixture' => 'tests/Fixtures/Suppliers/next_supplier_preview.csv',
            '--source-type' => 'csv',
            '--format' => 'json',
            '--limit' => 10,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('csv', $payload['summary']['source_type']);
        $this->assertSame(4, $payload['summary']['rows_scanned']);
        $this->assertSame(4, $payload['identifier_summary']['supplier_sku_present']);
        $this->assertSame(2, $payload['identifier_summary']['ean_gtin_present']);
        $this->assertSame(3, $payload['identifier_summary']['mpn_present']);
        $this->assertSame(1, $payload['identifier_summary']['duplicate_mpn_in_preview']);
        $this->assertSame(3, $payload['category_summary']['category_present']);
        $this->assertSame(3, $payload['price_stock_summary']['price_present']);
        $this->assertContains('missing_ean_gtin', $payload['preview_rows'][2]['issues']);
        $this->assertContains('missing_price', $payload['preview_rows'][3]['issues']);
    }

    /**
     * @throws JsonException
     */
    public function test_json_fixture_preview_is_supported(): void
    {
        $payload = $this->commandJson('suppliers:preview-staging-import', [
            '--fixture' => 'tests/Fixtures/Suppliers/next_supplier_preview.json',
            '--source-type' => 'json',
            '--format' => 'json',
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('json', $payload['summary']['source_type']);
        $this->assertSame(1, $payload['summary']['rows_scanned']);
        $this->assertSame('JSON-LAP-001', $payload['preview_rows'][0]['supplier_sku']);
    }

    public function test_remote_http_source_is_refused_without_fetching(): void
    {
        Http::fake();

        $status = Artisan::call('suppliers:preview-staging-import', [
            '--source' => 'https://example.com/feed.xml?token=SHOULD_NOT_APPEAR',
            '--source-type' => 'xml',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Remote feed fetching is disabled in preview-only phase.', $output);
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        Http::assertNothingSent();
    }

    public function test_unsupported_source_type_and_missing_file_fail_safely(): void
    {
        $unsupported = Artisan::call('suppliers:preview-staging-import', [
            '--fixture' => 'tests/Fixtures/Suppliers/next_supplier_preview.xml',
            '--source-type' => 'api',
        ]);

        $this->assertSame(1, $unsupported);
        $this->assertStringContainsString('Unsupported source type. Use xml, csv, json, or auto.', Artisan::output());

        $missing = Artisan::call('suppliers:preview-staging-import', [
            '--source' => 'tests/Fixtures/Suppliers/missing-file.xml',
            '--source-type' => 'xml',
        ]);

        $this->assertSame(1, $missing);
        $this->assertStringContainsString('Preview source file was not found.', Artisan::output());
    }

    /**
     * @throws JsonException
     */
    public function test_supplier_filter_and_overlap_detection_are_read_only(): void
    {
        $next = Supplier::factory()->create([
            'company_name' => 'Next Supplier Preview',
            'slug' => 'next-supplier-preview',
        ]);
        $other = Supplier::factory()->create([
            'company_name' => 'Other Preview Supplier',
            'slug' => 'other-preview-supplier',
        ]);

        $this->supplierProduct($next, [
            'supplier_sku' => 'NSP-LAP-001',
            'ean' => '5900000009999',
            'mpn' => 'EXISTING-MPN',
            'brand_name' => 'ExistingBrand',
            'name' => 'Existing supplier row',
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-EAN',
            'ean' => '5900000000002',
            'mpn' => 'OTHER-MPN',
            'brand_name' => 'OtherBrand',
            'name' => 'Other cross supplier row',
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-BRAND-MPN',
            'ean' => '5900000007777',
            'mpn' => 'NSP-MPN-002',
            'brand_name' => 'PixelForge',
            'name' => 'Other brand mpn row',
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-NAME',
            'ean' => '5900000008888',
            'mpn' => 'OTHER-NAME-MPN',
            'brand_name' => 'OtherNameBrand',
            'name' => 'Preview Product Missing Data',
        ]);

        $counts = $this->protectedCounts();

        $payload = $this->commandJson('suppliers:preview-staging-import', [
            '--supplier' => 'next-supplier-preview',
            '--fixture' => 'tests/Fixtures/Suppliers/next_supplier_preview.xml',
            '--source-type' => 'xml',
            '--format' => 'json',
            '--limit' => 10,
        ]);

        $this->assertSame($next->id, $payload['summary']['supplier_id']);
        $this->assertSame('Next Supplier Preview', $payload['summary']['supplier_name']);
        $this->assertSame(1, $payload['summary']['would_update_supplier_products']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['possible_cross_supplier_matches']);
        $this->assertSame('would_update_supplier_product', $payload['preview_rows'][0]['future_staging_action']);
        $this->assertTrue(collect($payload['overlaps'])->contains(fn (array $row): bool => $row['type'] === 'same_supplier_sku' && $row['scope'] === 'same_supplier'));
        $this->assertTrue(collect($payload['overlaps'])->contains(fn (array $row): bool => $row['type'] === 'ean_gtin' && $row['scope'] === 'cross_supplier'));
        $this->assertTrue(collect($payload['overlaps'])->contains(fn (array $row): bool => $row['type'] === 'brand_mpn' && $row['scope'] === 'cross_supplier'));
        $this->assertTrue(collect($payload['overlaps'])->contains(fn (array $row): bool => $row['type'] === 'normalized_name' && $row['confidence'] === 'low'));
        $this->assertSame($counts, $this->protectedCounts());
    }

    public function test_preview_does_not_mutate_protected_tables_or_expand_forbidden_surfaces(): void
    {
        Http::fake();
        Queue::fake([RunSupplierImportJob::class, ProcessXmlSupplierFeed::class, SyncProductJob::class]);
        Bus::fake([RunSupplierImportJob::class, ProcessSupplierImportRunJob::class, ProcessXmlSupplierFeed::class, SyncProductJob::class]);

        config([
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);

        $counts = $this->protectedCounts();

        $this->artisan('suppliers:preview-staging-import', [
            '--fixture' => 'tests/Fixtures/Suppliers/next_supplier_preview.csv',
            '--source-type' => 'csv',
            '--show-identifiers' => true,
            '--show-categories' => true,
            '--show-issues' => true,
        ])->assertSuccessful();

        $this->assertSame($counts, $this->protectedCounts());
        $this->assertSame(0, ProductSyncLog::query()->count());
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->get('/cart')->assertNotFound();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNotDispatched(RunSupplierImportJob::class);
        Bus::assertNotDispatched(ProcessSupplierImportRunJob::class);
        Bus::assertNotDispatched(ProcessXmlSupplierFeed::class);
        Bus::assertNotDispatched(SyncProductJob::class);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function commandJson(string $command, array $arguments): array
    {
        $this->assertSame(0, Artisan::call($command, $arguments));

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
            'products' => Product::query()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'categories' => Category::query()->count(),
            'suppliers' => Supplier::query()->count(),
            'supplier_category_mappings' => SupplierCategoryMapping::query()->count(),
            'canonical_product_families' => CanonicalProductFamily::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
            'catalog_sync_logs' => ProductSyncLog::query()->count(),
        ];
    }
}
