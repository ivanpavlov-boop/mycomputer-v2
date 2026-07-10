<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Tests\TestCase;

class AsbisDualFeedPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:preview-asbis-dual-feed', $commands);
        $definition = $commands['suppliers:preview-asbis-dual-feed']->getDefinition();

        $this->assertFalse($definition->hasOption('apply'));

        foreach ([
            'supplier',
            'product-list',
            'price-avail',
            'product-list-fixture',
            'price-avail-fixture',
            'join-key',
            'product-key',
            'price-key',
            'limit',
            'max-rows',
            'format',
            'show-field-map',
            'show-raw-fields',
            'show-normalized',
            'show-identifiers',
            'show-categories',
            'show-unmatched',
            'show-issues',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option), 'Missing option '.$option);
        }
    }

    public function test_required_supplier_asbis_scope_missing_files_and_remote_sources_are_guarded(): void
    {
        Http::fake();

        $this->assertSame(1, Artisan::call('suppliers:preview-asbis-dual-feed', [
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductList.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
        ]));
        $this->assertStringContainsString('The --supplier option is required.', Artisan::output());

        $other = Supplier::factory()->create(['company_name' => 'Other Supplier', 'slug' => 'other-supplier']);

        $this->assertSame(1, Artisan::call('suppliers:preview-asbis-dual-feed', [
            '--supplier' => $other->slug,
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductList.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
        ]));
        $this->assertStringContainsString('The --supplier option must resolve to ASBIS.', Artisan::output());

        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $this->assertSame(1, Artisan::call('suppliers:preview-asbis-dual-feed', [
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/MissingProductList.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
        ]));
        $missingFileOutput = Artisan::output();
        $this->assertStringContainsString('ProductList source file was not found.', $missingFileOutput);
        $this->assertStringContainsString('supplier_products changed: 0', $missingFileOutput);

        $this->assertSame(1, Artisan::call('suppliers:preview-asbis-dual-feed', [
            '--supplier' => 'asbis',
            '--product-list' => 'https://example.invalid/feed.xml?secret=SHOULD_NOT_APPEAR',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('Remote feed fetching is disabled for ASBIS dual-feed preview. Provide local files.', $output);
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', $output);

        Http::assertNothingSent();
    }

    /**
     * @throws JsonException
     */
    public function test_dual_feed_preview_joins_fixtures_and_reports_actions_without_writes(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier', 'slug' => 'other-supplier']);

        $this->supplierProduct($asbis, [
            'supplier_sku' => 'ASBIS-LAP-001',
            'ean' => '5901000000001',
            'mpn' => 'ASBIS-MPN-001',
            'brand_name' => 'TestBrand',
            'price' => 700.00,
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
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductList.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
            '--format' => 'json',
            '--limit' => 25,
            '--show-field-map' => true,
            '--show-identifiers' => true,
            '--show-categories' => true,
            '--show-unmatched' => true,
            '--show-issues' => true,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('preview_only', $payload['mode']);
        $this->assertSame('asbis', $payload['supplier']['key']);
        $this->assertSame('exact_key_match', $payload['join']['confidence']);
        $this->assertSame('ProductID', $payload['join']['product_key']);
        $this->assertSame('ProductID', $payload['join']['price_key']);
        $this->assertSame(10, $payload['summary']['product_list_rows']);
        $this->assertSame(11, $payload['summary']['price_avail_rows']);
        $this->assertSame(8, $payload['summary']['joined_rows']);
        $this->assertSame(2, $payload['summary']['would_create']);
        $this->assertSame(1, $payload['summary']['would_update']);
        $this->assertSame(1, $payload['summary']['product_only_rows']);
        $this->assertSame(2, $payload['summary']['price_only_rows']);
        $this->assertSame(2, $payload['summary']['duplicate_keys']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['cross_supplier_matches']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, $payload['records_changed']['catalog_sync']);

        $rows = collect($payload['joined_rows']);
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-LAP-001' && $row['future_staging_action'] === 'would_update_supplier_product'));
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-LAP-002' && $row['future_staging_action'] === 'would_create_supplier_product'));
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-MPN-ONLY-001' && in_array('missing_ean_gtin', $row['issues'], true) && $row['future_staging_action'] === 'would_need_manual_review'));
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-MISSING-PRICE-001' && in_array('missing_price', $row['issues'], true)));
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-NO-STOCK-001' && in_array('missing_stock_availability', $row['issues'], true)));
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-DUP-001' && in_array('duplicate_product_join_key', $row['issues'], true) && $row['future_staging_action'] === 'would_skip_row'));
        $this->assertTrue(collect($payload['unmatched_product_rows'])->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-PRODUCT-ONLY'));
        $this->assertTrue(collect($payload['unmatched_price_rows'])->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-PRICE-ONLY'));
        $this->assertSame('cdn.example.invalid', $rows->firstWhere('supplier_sku', 'ASBIS-LAP-001')['image_url_host']);

        $this->assertSame($counts, $this->protectedCounts());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /**
     * @throws JsonException
     */
    public function test_real_asbis_product_catalog_and_price_avail_structure_maps_fields_without_writes(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $this->supplierProduct($asbis, [
            'supplier_sku' => 'ASBIS-REAL-001',
            'ean' => '4750000000001',
            'brand_name' => 'RealBrand',
            'price' => 100.00,
        ]);

        $counts = $this->protectedCounts();

        $payload = $this->commandJson([
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductListReal.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvailReal.xml',
            '--format' => 'json',
            '--limit' => 25,
            '--show-field-map' => true,
            '--show-unmatched' => true,
            '--show-normalized' => true,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('inferred_key_match', $payload['join']['confidence']);
        $this->assertSame('ProductCode', $payload['join']['product_key']);
        $this->assertSame('WIC', $payload['join']['price_key']);
        $this->assertContains('productcode:wic', $payload['join']['candidate_normalized_keys']);

        $this->assertSame(3, $payload['summary']['product_list_rows']);
        $this->assertSame(4, $payload['summary']['price_avail_rows']);
        $this->assertSame(2, $payload['summary']['joined_rows']);
        $this->assertSame(1, $payload['summary']['would_update']);
        $this->assertSame(1, $payload['summary']['would_create']);
        $this->assertSame(1, $payload['summary']['product_only_rows']);
        $this->assertSame(2, $payload['summary']['price_only_rows']);
        $this->assertSame(0, $payload['records_changed']['products']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, $payload['records_changed']['catalog_sync']);

        $this->assertSame('ProductCode', $payload['detected_product_fields']['normalized_field_map']['supplier_sku']);
        $this->assertSame('Vendor', $payload['detected_product_fields']['normalized_field_map']['brand']);
        $this->assertSame('ProductCategory', $payload['detected_product_fields']['normalized_field_map']['category']);
        $this->assertSame('ProductDescription', $payload['detected_product_fields']['normalized_field_map']['description']);
        $this->assertSame('WIC', $payload['detected_price_fields']['normalized_field_map']['supplier_sku']);
        $this->assertSame('MY_PRICE', $payload['detected_price_fields']['normalized_field_map']['price']);
        $this->assertSame('RETAIL_PRICE', $payload['detected_price_fields']['normalized_field_map']['retail_price']);
        $this->assertSame('CURRENCY_CODE', $payload['detected_price_fields']['normalized_field_map']['currency']);
        $this->assertSame('AVAIL', $payload['detected_price_fields']['normalized_field_map']['stock']);
        $this->assertSame('AVAIL', $payload['detected_price_fields']['normalized_field_map']['availability']);
        $this->assertSame('EAN', $payload['detected_price_fields']['normalized_field_map']['ean_gtin']);
        $this->assertSame('DESCRIPTION', $payload['detected_price_fields']['normalized_field_map']['name']);

        $rows = collect($payload['joined_rows']);
        $existing = $rows->firstWhere('supplier_sku', 'ASBIS-REAL-001');
        $new = $rows->firstWhere('supplier_sku', 'ASBIS-REAL-002');

        $this->assertSame('would_update_supplier_product', $existing['future_staging_action']);
        $this->assertSame('RealBrand', $existing['brand']);
        $this->assertSame('Real ASBIS laptop from PriceAvail', $existing['name']);
        $this->assertSame('Computers / Laptops', $existing['category']);
        $this->assertSame(101.25, $existing['price']);
        $this->assertSame('EUR', $existing['currency']);
        $this->assertSame(24, $existing['stock']);
        $this->assertSame('24+', $existing['availability']);
        $this->assertSame('images.example.invalid', $existing['image_url_host']);
        $this->assertTrue($existing['description_present']);

        $this->assertSame('would_create_supplier_product', $new['future_staging_action']);
        $this->assertSame(220.50, $new['price']);
        $this->assertSame(0, $new['stock']);
        $this->assertSame('0', $new['availability']);
        $this->assertSame('Real ASBIS dock from PriceAvail', $new['name']);

        $this->assertTrue(collect($payload['unmatched_product_rows'])->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-REAL-PRODUCT-ONLY'));
        $this->assertTrue(collect($payload['unmatched_price_rows'])->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-REAL-PRICE-ONLY'));
        $this->assertTrue(collect($payload['unmatched_price_rows'])->contains(fn (array $row): bool => $row['supplier_sku'] === 'ASBIS-REAL-NO-PRODUCT'));

        $explicit = $this->commandJson([
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductListReal.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvailReal.xml',
            '--product-key' => 'ProductCode',
            '--price-key' => 'WIC',
            '--format' => 'json',
        ]);

        $this->assertSame('explicit_key_match', $explicit['join']['confidence']);
        $this->assertSame(2, $explicit['summary']['joined_rows']);

        $this->assertSame($counts, $this->protectedCounts());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /**
     * @throws JsonException
     */
    public function test_explicit_join_keys_work_and_ambiguous_auto_join_is_safe(): void
    {
        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $ambiguous = $this->commandJson([
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductListAmbiguous.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvailAmbiguous.xml',
            '--format' => 'json',
            '--show-issues' => true,
        ]);

        $this->assertTrue($ambiguous['success']);
        $this->assertSame('ambiguous_join_key', $ambiguous['join']['confidence']);
        $this->assertTrue(collect($ambiguous['issues'])->contains(fn (array $issue): bool => $issue['reason'] === 'ambiguous_join_key'));
        $this->assertSame(0, $ambiguous['records_changed']['supplier_products']);

        $explicit = $this->commandJson([
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductListAmbiguous.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvailAmbiguous.xml',
            '--product-key' => 'ProductID',
            '--price-key' => 'ProductID',
            '--format' => 'json',
        ]);

        $this->assertTrue($explicit['success']);
        $this->assertSame('explicit_key_match', $explicit['join']['confidence']);
        $this->assertSame(1, $explicit['summary']['joined_rows']);
    }

    /**
     * @throws JsonException
     */
    public function test_limit_and_max_rows_are_capped_and_json_contract_contains_required_sections(): void
    {
        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $payload = $this->commandJson([
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductList.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
            '--format' => 'json',
            '--limit' => 2,
            '--max-rows' => 3,
            '--show-normalized' => true,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame(3, $payload['summary']['product_list_rows']);
        $this->assertSame(3, $payload['summary']['price_avail_rows']);
        $this->assertCount(2, $payload['joined_rows']);

        foreach ([
            'success',
            'mode',
            'supplier',
            'sources',
            'join',
            'summary',
            'detected_product_fields',
            'detected_price_fields',
            'normalized_coverage',
            'identifier_summary',
            'category_summary',
            'price_stock_summary',
            'joined_rows',
            'overlaps',
            'issues',
            'records_changed',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertArrayHasKey('normalized', $payload['joined_rows'][0]);
    }

    public function test_table_output_prints_all_zero_change_counters_and_no_full_image_urls(): void
    {
        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $this->assertSame(0, Artisan::call('suppliers:preview-asbis-dual-feed', [
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductList.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
            '--limit' => 3,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('ASBIS dual-feed preview', $output);
        $this->assertStringContainsString('Preview-only. No supplier_products writes', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('catalog_sync changed: 0', $output);
        $this->assertStringNotContainsString('cdn.example.invalid/asbis', $output);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function commandJson(array $arguments, int $expectedStatus = 0): array
    {
        $this->assertSame($expectedStatus, Artisan::call('suppliers:preview-asbis-dual-feed', $arguments));

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
