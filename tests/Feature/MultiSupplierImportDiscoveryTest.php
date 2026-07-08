<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupplierImportRunJob;
use App\Jobs\ProcessXmlSupplierFeed;
use App\Jobs\RunSupplierImportJob;
use App\Models\AttributeValue;
use App\Models\CanonicalProductFamily;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use JsonException;
use Tests\TestCase;

class MultiSupplierImportDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:audit-discovery', $commands);
        $this->assertFalse($commands['suppliers:audit-discovery']->getDefinition()->hasOption('apply'));
        $this->assertTrue($commands['suppliers:audit-discovery']->getDefinition()->hasOption('supplier'));
        $this->assertTrue($commands['suppliers:audit-discovery']->getDefinition()->hasOption('format'));
        $this->assertTrue($commands['suppliers:audit-discovery']->getDefinition()->hasOption('only-with-issues'));
        $this->assertTrue($commands['suppliers:audit-discovery']->getDefinition()->hasOption('show-categories'));
        $this->assertTrue($commands['suppliers:audit-discovery']->getDefinition()->hasOption('show-identifiers'));
        $this->assertTrue($commands['suppliers:audit-discovery']->getDefinition()->hasOption('show-overlaps'));
    }

    public function test_discovery_runs_default_and_does_not_mutate_or_dispatch_imports(): void
    {
        Bus::fake();

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-001',
            'ean' => '1234567890123',
            'mpn' => 'MPN-001',
            'brand_name' => 'Lenovo',
            'category_name' => 'Laptops',
            'price' => 1200,
            'quantity' => 5,
        ]);

        $counts = $this->protectedCounts();

        $this->assertSame(0, Artisan::call('suppliers:audit-discovery'));
        $output = Artisan::output();

        $this->assertStringContainsString('Multi-supplier import discovery audit', $output);
        $this->assertStringContainsString('Read-only. No imports, sync, mapping approvals, or catalog writes were run.', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('supplier_category_mappings changed: 0', $output);
        $this->assertSame($counts, $this->protectedCounts());
        Bus::assertNotDispatched(RunSupplierImportJob::class);
        Bus::assertNotDispatched(ProcessXmlSupplierFeed::class);
        Bus::assertNotDispatched(ProcessSupplierImportRunJob::class);
    }

    /**
     * @throws JsonException
     */
    public function test_json_reports_supplier_summary_category_mapping_counts_and_readiness(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $family = CanonicalProductFamily::query()->create([
            'code' => 'laptops',
            'name_bg' => 'Laptops',
            'name_en' => 'Laptops',
        ]);

        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-LAP-001',
            'ean' => '1111111111111',
            'mpn' => 'LAP-001',
            'brand_name' => 'Lenovo',
            'category_name' => 'Laptops',
            'price' => 999,
            'quantity' => 4,
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-MON-001',
            'ean' => '2222222222222',
            'mpn' => 'MON-001',
            'brand_name' => 'Dell',
            'category_name' => 'Monitors',
            'price' => 299,
            'quantity' => 8,
        ]);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_name' => 'APCOM',
            'supplier_category_name' => 'Laptops',
            'canonical_product_family_id' => $family->id,
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);

        $payload = $this->commandJson('suppliers:audit-discovery', [
            '--format' => 'json',
            '--show-categories' => true,
        ]);

        $this->assertSame(1, $payload['summary']['suppliers_checked']);
        $this->assertSame(2, $payload['summary']['staged_supplier_products']);
        $this->assertSame(2, $payload['category_summary']['distinct_supplier_categories']);
        $this->assertSame(1, $payload['category_summary']['mapping_status_counts']['approved']);
        $this->assertSame(1, $payload['category_summary']['mapping_status_counts']['unmapped']);

        $supplierRow = $payload['suppliers'][0];

        $this->assertSame('APCOM', $supplierRow['supplier_name']);
        $this->assertSame(2, $supplierRow['staged_supplier_products_count']);
        $this->assertSame(2, $supplierRow['products_with_supplier_sku']);
        $this->assertSame(2, $supplierRow['products_with_ean_gtin_barcode']);
        $this->assertSame(2, $supplierRow['distinct_supplier_categories_count']);
        $this->assertSame('ready_for_mapping_review', $supplierRow['readiness_status']);
    }

    /**
     * @throws JsonException
     */
    public function test_supplier_filter_and_only_with_issues_are_supported(): void
    {
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier', 'slug' => 'other']);

        $this->supplierProduct($apcom, [
            'supplier_sku' => 'APC-READY',
            'ean' => '3333333333333',
            'mpn' => 'READY-1',
            'brand_name' => 'HP',
            'category_name' => 'Laptops',
            'price' => 500,
            'quantity' => 1,
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => '',
            'ean' => '',
            'mpn' => '',
            'brand_name' => 'No Identifier Brand',
            'category_name' => null,
        ]);

        $filtered = $this->commandJson('suppliers:audit-discovery', [
            '--format' => 'json',
            '--supplier' => 'apcom',
        ]);

        $this->assertCount(1, $filtered['suppliers']);
        $this->assertSame('APCOM', $filtered['suppliers'][0]['supplier_name']);

        $issuesOnly = $this->commandJson('suppliers:audit-discovery', [
            '--format' => 'json',
            '--only-with-issues' => true,
        ]);

        $this->assertCount(1, $issuesOnly['suppliers']);
        $this->assertSame('Other Supplier', $issuesOnly['suppliers'][0]['supplier_name']);
        $this->assertSame('needs_category_data', $issuesOnly['suppliers'][0]['readiness_status']);
    }

    /**
     * @throws JsonException
     */
    public function test_identifier_discovery_reports_missing_and_duplicate_values(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);

        $this->supplierProduct($supplier, [
            'supplier_sku' => 'DUP-SKU',
            'ean' => '4444444444444',
            'mpn' => 'DUP-MPN',
            'brand_name' => 'Acer',
            'category_name' => 'Laptops',
            'price' => 800,
            'quantity' => 3,
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'DUP-SKU',
            'ean' => '4444444444444',
            'mpn' => 'DUP-MPN',
            'brand_name' => null,
            'category_name' => 'Laptops',
            'price' => null,
            'quantity' => null,
        ]);

        $payload = $this->commandJson('suppliers:audit-discovery', [
            '--format' => 'json',
            '--show-identifiers' => true,
        ]);

        $this->assertSame(2, $payload['identifier_summary']['total_staged_products']);
        $this->assertSame(1, $payload['identifier_summary']['products_missing_brand']);
        $this->assertSame(1, $payload['identifier_summary']['duplicate_supplier_sku_within_supplier']);
        $this->assertSame(1, $payload['identifier_summary']['duplicate_ean_gtin_within_supplier']);
        $this->assertSame(1, $payload['identifier_summary']['duplicate_mpn_within_supplier']);
        $this->assertSame(1, $payload['identifier_summary']['per_supplier'][0]['potential_duplicate_supplier_sku_within_supplier']);
        $this->assertSame('needs_identifier_cleanup', $payload['suppliers'][0]['readiness_status']);
    }

    /**
     * @throws JsonException
     */
    public function test_category_discovery_reports_mapped_pending_and_unmapped_without_creating_mappings(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $this->supplierProduct($supplier, ['category_name' => 'Power & Cable']);
        $this->supplierProduct($supplier, ['category_name' => 'Cases & Protection']);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_name' => 'APCOM',
            'supplier_category_name' => 'Power & Cable',
            'status' => SupplierCategoryMapping::STATUS_PENDING_REVIEW,
        ]);

        $before = SupplierCategoryMapping::query()->count();
        $payload = $this->commandJson('suppliers:audit-discovery', [
            '--format' => 'json',
            '--show-categories' => true,
        ]);
        $categories = collect($payload['categories'])->keyBy('supplier_category_name');

        $this->assertSame($before, SupplierCategoryMapping::query()->count());
        $this->assertSame('pending_review', $categories['Power & Cable']['mapping_status']);
        $this->assertSame('review pending mapping', $categories['Power & Cable']['next_action']);
        $this->assertSame('unmapped', $categories['Cases & Protection']['mapping_status']);
        $this->assertSame('create mapping candidate', $categories['Cases & Protection']['next_action']);
    }

    /**
     * @throws JsonException
     */
    public function test_overlap_discovery_reports_cross_supplier_identifier_matches_read_only(): void
    {
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $also = Supplier::factory()->create(['company_name' => 'Second Supplier', 'slug' => 'second']);

        $this->supplierProduct($apcom, [
            'supplier_sku' => 'APC-OVERLAP',
            'ean' => '5555555555555',
            'mpn' => 'BRAND-MPN-1',
            'brand_name' => 'Lenovo',
            'name' => 'Lenovo ThinkPad X1 Carbon',
            'category_name' => 'Laptops',
        ]);
        $this->supplierProduct($also, [
            'supplier_sku' => 'OTHER-OVERLAP',
            'ean' => '5555555555555',
            'mpn' => 'BRAND-MPN-1',
            'brand_name' => 'Lenovo',
            'name' => 'Lenovo ThinkPad X1 Carbon',
            'category_name' => 'Notebooks',
        ]);

        $counts = $this->protectedCounts();
        $payload = $this->commandJson('suppliers:audit-discovery', [
            '--format' => 'json',
            '--show-overlaps' => true,
        ]);
        $overlaps = collect($payload['overlaps'])->keyBy('identifier_type');

        $this->assertSame($counts, $this->protectedCounts());
        $this->assertSame(1, $payload['overlap_summary']['ean_gtin']);
        $this->assertSame(1, $payload['overlap_summary']['brand_mpn']);
        $this->assertSame(1, $payload['overlap_summary']['normalized_name_low_confidence']);
        $this->assertSame('high', $overlaps['ean_gtin']['confidence']);
        $this->assertSame('medium', $overlaps['brand_mpn']['confidence']);
        $this->assertSame('low', $overlaps['normalized_name']['confidence']);
        $this->assertSame('ignore weak match', $overlaps['normalized_name']['next_action']);
    }

    /**
     * @throws JsonException
     */
    public function test_records_changed_json_includes_all_protected_tables_and_sync_flags_remain_locked(): void
    {
        $payload = $this->commandJson('suppliers:audit-discovery', [
            '--format' => 'json',
            '--include-empty' => true,
            '--limit' => 10,
        ]);

        $this->assertSame([
            'products' => 0,
            'supplier_products' => 0,
            'categories' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
        ], $payload['records_changed']);

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->get('/cart')->assertNotFound();
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
            'supplier_category_mappings' => SupplierCategoryMapping::query()->count(),
            'canonical_product_families' => CanonicalProductFamily::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
        ];
    }
}
