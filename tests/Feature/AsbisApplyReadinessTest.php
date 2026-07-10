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

class AsbisApplyReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('suppliers:audit-asbis-apply-readiness', $commands);
        $definition = $commands['suppliers:audit-asbis-apply-readiness']->getDefinition();

        $this->assertFalse($definition->hasOption('apply'));

        foreach ([
            'supplier',
            'product-list',
            'price-avail',
            'product-list-fixture',
            'price-avail-fixture',
            'product-key',
            'price-key',
            'format',
            'sample-limit',
            'issue-sample-limit',
            'summary-only',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option), 'Missing option '.$option);
        }
    }

    /**
     * @throws JsonException
     */
    public function test_real_style_readiness_audit_classifies_rows_and_never_mutates(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);
        $other = Supplier::factory()->create(['company_name' => 'Other', 'slug' => 'other']);
        $this->supplierProduct($asbis, [
            'supplier_sku' => 'ASBIS-REAL-001',
            'ean' => '4750000000001',
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-REAL-002',
            'ean' => '4750000000002',
        ]);
        $counts = $this->protectedCounts();

        $payload = $this->commandJson([
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductListReal.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvailReal.xml',
            '--format' => 'json',
            '--sample-limit' => 2,
            '--issue-sample-limit' => 1,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('apply_readiness_audit', $payload['mode']);
        $this->assertSame('ProductCode', $payload['join']['product_key']);
        $this->assertSame('WIC', $payload['join']['price_key']);
        $this->assertSame('streaming_xmlreader', $payload['parser']['parser_mode']);
        $this->assertTrue($payload['parser']['full_file_completed']);
        $this->assertSame(6, $payload['summary']['product_list_rows']);
        $this->assertSame(7, $payload['summary']['price_avail_rows']);
        $this->assertSame(5, $payload['summary']['joined_rows']);
        $this->assertSame(1, $payload['summary']['ready_to_update']);
        $this->assertSame(1, $payload['summary']['ready_to_create']);
        $this->assertSame(1, $payload['summary']['ready_with_warning']);
        $this->assertSame(2, $payload['summary']['manual_review']);
        $this->assertSame(1, $payload['summary']['product_only_rows']);
        $this->assertSame(2, $payload['summary']['price_only_rows']);
        $this->assertSame('ready_with_warnings', $payload['readiness']['verdict']);
        $this->assertSame(3, $payload['readiness']['apply_candidate_count']);
        $this->assertSame(5, $payload['readiness']['apply_blocker_count']);
        $this->assertSame(3, $payload['availability_audit']['normalized_in_stock_count']);
        $this->assertSame(1, $payload['availability_audit']['normalized_limited_stock_count']);
        $this->assertSame(1, $payload['availability_audit']['normalized_on_request_count']);
        $this->assertSame(1, $payload['availability_audit']['unknown_availability_count']);
        $this->assertSame(1, $payload['availability_audit']['missing_availability_count']);
        $this->assertSame(6, $payload['pricing_audit']['valid_my_price_count']);
        $this->assertSame(1, $payload['pricing_audit']['retail_price_fallback_count']);
        $this->assertSame(1, $payload['overlap_audit']['ean_overlap_groups']);
        $this->assertCount(2, $payload['ready_samples']);
        $this->assertCount(1, $payload['issue_samples']);

        foreach ($payload['records_changed'] as $count) {
            $this->assertSame(0, $count);
        }

        $this->assertSame($counts, $this->protectedCounts());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /**
     * @throws JsonException
     */
    public function test_duplicate_join_keys_block_readiness_and_identifier_issues_are_exact(): void
    {
        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $payload = $this->commandJson([
            '--supplier' => 'asbis',
            '--product-list-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/ProductList.xml',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
            '--product-key' => 'ProductID',
            '--price-key' => 'ProductID',
            '--format' => 'json',
        ]);

        $this->assertSame(1, $payload['identifier_audit']['duplicate_product_code_keys']);
        $this->assertSame(1, $payload['identifier_audit']['duplicate_wic_keys']);
        $this->assertSame('not_ready_for_controlled_staging_apply', $payload['readiness']['verdict']);
        $this->assertGreaterThanOrEqual(1, $payload['issue_counts']['duplicate_product_code']);
        $this->assertGreaterThanOrEqual(1, $payload['issue_counts']['duplicate_wic']);
        $this->assertGreaterThanOrEqual(1, $payload['issue_counts']['missing_ean_and_mpn']);
        $this->assertGreaterThanOrEqual(1, $payload['issue_counts']['warning:missing_ean_with_mpn']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, $payload['records_changed']['catalog_sync']);
    }

    public function test_remote_sources_are_refused_without_fetch_or_secret_output(): void
    {
        Http::fake();
        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $this->assertSame(1, Artisan::call('suppliers:audit-asbis-apply-readiness', [
            '--supplier' => 'asbis',
            '--product-list' => 'https://example.invalid/ProductList.xml?secret=SHOULD_NOT_APPEAR',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvailReal.xml',
        ]));

        $this->assertStringContainsString('Remote feed fetching is disabled', Artisan::output());
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', Artisan::output());
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function commandJson(array $arguments): array
    {
        $this->assertSame(0, Artisan::call('suppliers:audit-asbis-apply-readiness', $arguments));

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
