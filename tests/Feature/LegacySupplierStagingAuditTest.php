<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Tests\TestCase;

class LegacySupplierStagingAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_read_only_and_has_no_write_controls(): void
    {
        $command = Artisan::all()['suppliers:audit-legacy-staging-state'];
        $definition = $command->getDefinition();

        $this->assertStringContainsString('Read-only', (string) $command->getDescription());
        $this->assertStringContainsString('no import', (string) $command->getDescription());
        $this->assertStringContainsString('no writes', (string) $command->getDescription());

        foreach (['supplier', 'include-linked-analysis', 'include-status-counts', 'include-identifier-diagnostics', 'include-catalog-comparison', 'include-mapping-analysis', 'include-import-history', 'output', 'summary-only', 'sample-limit', 'issue-sample-limit', 'sort'] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }

        foreach (['apply', 'fix', 'repair', 'unlink', 'link', 'import', 'sync', 'sync-all', 'create', 'update', 'delete', 'fetch', 'schedule', 'enable', 'disable', 'dispatch', 'queue', 'download', 'confirm-'] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
    }

    public function test_supplier_is_required_and_invalid_supplier_fails_without_side_effects(): void
    {
        $this->assertSame(1, Artisan::call('suppliers:audit-legacy-staging-state', ['--output' => 'json']));
        $this->assertSame(1, Artisan::call('suppliers:audit-legacy-staging-state', ['--supplier' => 'not-a-real-supplier', '--output' => 'json']));
        $this->assertStringNotContainsString('not-a-real-supplier', Artisan::output());
    }

    /** @throws JsonException */
    public function test_apcom_style_inventory_links_identifiers_mappings_and_zero_mutations(): void
    {
        Bus::fake();
        Http::fake();
        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM Legacy',
            'slug' => 'apcom',
            'status' => 'active',
            'import_enabled' => true,
            'schedule_enabled' => false,
            'schedule_type' => 'manual_only',
        ]);
        SupplierFeed::factory()->create([
            'supplier_id' => $supplier->id,
            'feed_type' => 'xml',
            'feed_url' => 'https://private.example.invalid/feed?secret=hidden',
            'username' => 'private-user',
            'password' => 'private-password',
        ]);
        $product = Product::factory()->create();
        $this->staged($supplier, ['supplier_sku' => 'AP-001', 'ean' => '5901234123457', 'mpn' => 'MODEL-001', 'product_id' => $product->id, 'status' => 'synced']);
        $this->staged($supplier, ['supplier_sku' => 'AP-001', 'ean' => '5901234123457', 'mpn' => 'MODEL-001', 'product_id' => $product->id]);
        $this->staged($supplier, ['supplier_sku' => ' ', 'ean' => 'invalid-ean', 'mpn' => 'MODEL-002', 'price' => '-1']);
        $this->staged($supplier, ['supplier_sku' => null, 'ean' => null, 'mpn' => null, 'quantity' => 2]);
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_key' => 'apcom',
            'supplier_name' => 'APCOM Legacy',
            'supplier_category_name' => 'Laptops',
            'supplier_category_hash' => hash('sha256', 'apcom:laptops'),
            'status' => SupplierCategoryMapping::STATUS_PENDING_REVIEW,
        ]);

        $before = $this->protectedCounts();
        $payload = $this->commandJson([
            '--supplier' => 'apcom',
            '--include-linked-analysis' => true,
            '--include-status-counts' => true,
            '--include-identifier-diagnostics' => true,
            '--include-catalog-comparison' => true,
            '--include-mapping-analysis' => true,
            '--include-import-history' => true,
            '--output' => 'json',
            '--sample-limit' => 1,
        ]);

        $this->assertSame('supplier-legacy-staging-audit-v1', $payload['schema_version']);
        $this->assertSame('legacy_staging_audit', $payload['mode']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('apcom', $payload['supplier']['key']);
        $this->assertSame('supplier_1_historically_integrated', $payload['supplier']['role']);
        $this->assertSame(4, $payload['staging_inventory']['total_rows']);
        $this->assertSame(2, $payload['staging_inventory']['linked_rows']);
        $this->assertSame(2, $payload['staging_inventory']['unlinked_rows']);
        $this->assertSame(1, $payload['identifier_diagnostics']['supplier_sku']['duplicate_groups']['group_count']);
        $this->assertSame(1, $payload['identifier_diagnostics']['ean']['invalid_format_count']);
        $this->assertSame(1, $payload['linked_state_analysis']['distinct_linked_catalog_product_count']);
        $this->assertSame(1, $payload['linked_state_analysis']['multiple_apcom_rows_linked_to_one_product_count']);
        $this->assertSame(1, $payload['mapping_state']['pending_mapping_count']);
        $this->assertTrue($payload['catalog_comparison']['equality_does_not_prove_overwrite']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-user', $encoded);
        $this->assertStringNotContainsString('private-password', $encoded);
        $this->assertStringNotContainsString('private.example.invalid', $encoded);
        $this->assertStringNotContainsString('AP-001', $encoded);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_linked_staging_and_enabled_schedule_require_a_freeze_but_do_not_fail_as_an_internal_error(): void
    {
        $supplier = Supplier::factory()->create([
            'slug' => 'apcom',
            'schedule_enabled' => true,
            'schedule_type' => 'twice_daily',
        ]);
        $product = Product::factory()->create();
        $this->staged($supplier, ['product_id' => $product->id]);

        $payload = $this->commandJson(['--supplier' => 'apcom', '--output' => 'json']);

        $this->assertSame('schedule_must_be_frozen', $payload['verdict']);
        $this->assertContains('schedule_must_be_frozen', $payload['blockers']);
        $this->assertTrue($payload['schedule_safety']['schedule_can_change_supplier_products_during_audit']);
        $this->assertFalse($payload['schedule_safety']['schedule_was_modified']);
    }

    public function test_unsafe_catalog_sync_flags_fail_without_changing_any_flag(): void
    {
        $supplier = Supplier::factory()->create(['slug' => 'apcom']);
        config(['catalog_sync.update_enabled' => true]);

        $this->assertSame(1, Artisan::call('suppliers:audit-legacy-staging-state', [
            '--supplier' => $supplier->slug,
            '--output' => 'json',
        ]));
        $output = Artisan::output();
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('unsafe_configuration', $payload['verdict']);
        $this->assertTrue($payload['global_safety_flags']['catalog_sync_update_enabled']);
        $this->assertSame(0, array_sum($payload['records_changed']));
    }

    public function test_table_output_is_readable_and_summary_only_hides_detailed_supplier_values(): void
    {
        Supplier::factory()->create(['slug' => 'apcom']);

        $this->assertSame(0, Artisan::call('suppliers:audit-legacy-staging-state', [
            '--supplier' => 'apcom',
            '--summary-only' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('Legacy supplier staging audit', $output);
        $this->assertStringContainsString('Read-only.', $output);
        $this->assertStringContainsString('Verdict:', $output);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function commandJson(array $arguments): array
    {
        $this->assertSame(0, Artisan::call('suppliers:audit-legacy-staging-state', $arguments));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $overrides */
    private function staged(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'AP-DEFAULT',
            'ean' => null,
            'mpn' => null,
            'name' => 'Fixture supplier product',
            'brand_name' => 'Fixture brand',
            'category_name' => 'Laptops',
            'price' => '100.00',
            'quantity' => 1,
            'currency' => 'EUR',
            'raw_data' => ['source' => 'synthetic_fixture'],
            'payload_hash' => hash('sha256', uniqid('', true)),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        return collect([
            'suppliers' => 'suppliers',
            'supplier_products' => 'supplier_products',
            'products' => 'products',
            'categories' => 'categories',
            'supplier_category_mappings' => 'supplier_category_mappings',
            'canonical_product_families' => 'canonical_product_families',
            'category_product_attributes' => 'category_product_attributes',
            'product_attributes' => 'product_attributes',
            'attribute_values' => 'attribute_values',
            'product_attribute_values' => 'product_attribute_values',
            'catalog_sync_batches' => 'catalog_sync_batches',
            'catalog_sync_logs' => 'catalog_sync_logs',
        ])->mapWithKeys(fn (string $table, string $key): array => [$key => Schema::hasTable($table) ? DB::table($table)->count() : 0])->put('catalog_sync', 0)->all();
    }
}
