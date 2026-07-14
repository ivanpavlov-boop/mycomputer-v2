<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierImportRun;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Tests\TestCase;

class LocalSupplierSourceNormalizationPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_read_only_and_has_no_unsafe_controls(): void
    {
        $command = Artisan::all()['suppliers:plan-local-source-normalization'];
        $definition = $command->getDefinition();

        $this->assertStringContainsString('Read-only', (string) $command->getDescription());
        $this->assertStringContainsString('no remote', (string) $command->getDescription());

        foreach ([
            'supplier', 'source', 'source-format', 'record-path', 'expected-sha256', 'full-file',
            'expected-supplier-id', 'expected-schedule-enabled', 'expected-import-enabled', 'expected-schedule-type',
            'expected-staged-count', 'expected-linked-count', 'expected-unlinked-count', 'expected-last-import-at',
            'output', 'summary-only', 'sample-limit', 'issue-sample-limit',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }

        foreach (['apply', 'fix', 'repair', 'unlink', 'link', 'import', 'sync', 'sync-all', 'create', 'update', 'delete', 'fetch', 'schedule', 'enable', 'disable', 'dispatch', 'queue', 'download', 'confirm-'] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
    }

    /** @throws JsonException */
    public function test_synthetic_apcom_plan_is_deterministic_read_only_and_never_emits_fixture_values(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedFixtureRows($supplier, 5, 2);
        Bus::fake();
        Http::fake();
        $before = $this->protectedCounts();

        $payload = $this->commandJson($this->baselineArguments($supplier));

        $this->assertSame('supplier-local-source-normalization-plan-v1', $payload['schema_version']);
        $this->assertSame('local_source_normalization_plan', $payload['mode']);
        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['human_review_required']);
        $this->assertFalse($payload['executable_import_config_created']);
        $this->assertFalse($payload['persisted_feed_profile_created']);
        $this->assertSame('apcom', $payload['supplier']['key']);
        $this->assertSame(5, $payload['source_record_count']);
        $this->assertSame(5, $payload['legacy_staging_count']);
        $this->assertSame(0, $payload['record_count_delta']);
        $this->assertEquals(0.0, $payload['record_count_delta_percentage']);
        $this->assertTrue($payload['baseline_lock']['matches']);
        $this->assertSame('clear', $payload['active_import_check']['state']);
        $this->assertFalse($payload['observed_state']['schedule_enabled']);
        $this->assertTrue($payload['global_safety_flags']['catalog_sync_create_enabled']);
        $this->assertFalse($payload['global_safety_flags']['catalog_sync_update_enabled']);
        $this->assertFalse($payload['global_safety_flags']['catalog_sync_sync_all_enabled']);
        $this->assertFalse($payload['global_safety_flags']['catalog_sync_auto_enabled']);
        $this->assertSame('SupplierSku', $payload['field_coverage']['supplier_sku']['source_field_path']);
        $this->assertEquals(100.0, $payload['field_coverage']['price']['coverage_percentage']);
        $this->assertEquals(80.0, $payload['field_coverage']['ean']['coverage_percentage']);
        $this->assertTrue($payload['field_coverage']['price']['review_required']);
        $this->assertFalse($payload['field_coverage']['price']['catalog_mutation_allowed']);
        $this->assertEquals(100.0, $payload['field_compatibility']['price']['source_coverage_percentage']);
        $this->assertEquals(100.0, $payload['field_compatibility']['price']['legacy_staging_coverage_percentage']);
        $this->assertGreaterThanOrEqual(1, $payload['collision_policy']['duplicate_supplier_sku_risk']['whitespace_normalized_duplicate_groups']['group_count']);
        $this->assertFalse($payload['collision_policy']['automatic_link_merge_delete_or_correction_allowed']);
        $this->assertTrue($payload['collision_policy']['samples']['diagnostic_bounds']['enabled']);
        $this->assertSame(64, $payload['collision_policy']['samples']['diagnostic_bounds']['max_fields']);
        $this->assertSame(1000, $payload['collision_policy']['samples']['diagnostic_bounds']['max_distinct_values_per_field']);
        $this->assertFalse($payload['image_policy']['image_import_enabled']);
        $this->assertFalse($payload['image_policy']['image_downloaded']);
        $this->assertFalse($payload['attribute_policy']['supplier_attribute_written_to_catalog']);
        $this->assertFalse($payload['category_mapping_policy']['new_mapping_persisted']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SYN-AP-001', $encoded);
        $this->assertStringNotContainsString('images.example.test', $encoded);
        $this->assertStringNotContainsString('Synthetic APCOM workstation', $encoded);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_explicit_record_path_and_material_source_delta_require_human_review_without_writes(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedFixtureRows($supplier, 2, 1);
        Bus::fake();
        Http::fake();
        $before = $this->protectedCounts();

        $payload = $this->commandJson(array_merge($this->baselineArguments($supplier), [
            '--record-path' => 'SyntheticApcomCatalog/Product',
        ]));

        $this->assertTrue($payload['success']);
        $this->assertSame('plan_requires_source_review', $payload['verdict']);
        $this->assertSame(5, $payload['source_record_count']);
        $this->assertSame(2, $payload['legacy_staging_count']);
        $this->assertSame(3, $payload['record_count_delta']);
        $this->assertContains('source_record_count_material_drift', $payload['warnings']);
        $this->assertSame('explicit_option', $payload['source_profile']['record_path_analysis']['selection_reason']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_missing_required_inputs_and_rejected_sources_fail_without_side_effects(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedFixtureRows($supplier, 5, 2);
        Bus::fake();
        Http::fake();
        $valid = $this->baselineArguments($supplier);

        foreach ([
            [],
            array_diff_key($valid, ['--expected-sha256' => true]),
            array_diff_key($valid, ['--expected-staged-count' => true]),
            array_merge($valid, ['--source' => 'https://example.invalid/fixture.xml']),
            array_merge($valid, ['--source' => base_path('tests/Fixtures/Suppliers/apcom_local_source')]),
            array_merge($valid, ['--source-format' => 'csv']),
            array_merge($valid, ['--expected-sha256' => str_repeat('a', 64)]),
        ] as $arguments) {
            $this->assertSame(1, Artisan::call('suppliers:plan-local-source-normalization', array_merge($arguments, ['--output' => 'json'])));
            $output = Artisan::output();
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($payload['success']);
            $this->assertSame(0, array_sum($payload['records_changed']));
            $this->assertStringNotContainsString('example.invalid', $output);
        }

        Supplier::factory()->create(['company_name' => 'Duplicate Supplier', 'slug' => 'duplicate-one']);
        Supplier::factory()->create(['company_name' => 'Duplicate Supplier', 'slug' => 'duplicate-two']);
        $afterAmbiguityFixtures = $this->protectedCounts();
        $ambiguous = $this->failedCommandJson(array_merge($valid, ['--supplier' => 'Duplicate Supplier']));
        $this->assertSame('audit_failed', $ambiguous['verdict']);
        $this->assertContains('supplier_ambiguous', $ambiguous['blockers']);

        $this->assertSame($afterAmbiguityFixtures, $this->protectedCounts());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_baseline_schedule_and_import_state_mismatches_refuse_safely(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedFixtureRows($supplier, 5, 2);
        $valid = $this->baselineArguments($supplier);

        foreach ([
            ['--expected-supplier-id', (string) ($supplier->id + 1), 'expected_supplier_id_mismatch'],
            ['--expected-staged-count', '4', 'expected_staged_count_mismatch'],
            ['--expected-linked-count', '1', 'expected_linked_count_mismatch'],
            ['--expected-unlinked-count', '2', 'expected_unlinked_count_mismatch'],
            ['--expected-import-enabled', 'false', 'expected_import_state_mismatch'],
            ['--expected-schedule-type', 'manual_only', 'expected_schedule_type_mismatch'],
            ['--expected-last-import-at', '2025-01-01 00:00:00', 'expected_last_import_at_mismatch'],
        ] as [$option, $value, $blocker]) {
            $payload = $this->failedCommandJson(array_merge($valid, [$option => $value]));
            $this->assertContains($blocker, $payload['blockers']);
            $this->assertSame(0, array_sum($payload['records_changed']));
        }

        $scheduleArguments = array_merge($valid, ['--expected-schedule-enabled' => 'true']);
        $supplier->update(['schedule_enabled' => true]);
        $schedulePayload = $this->failedCommandJson($scheduleArguments);
        $this->assertContains('schedule_not_frozen', $schedulePayload['blockers']);
        $supplier->update(['schedule_enabled' => false]);

        $mismatchPayload = $this->failedCommandJson(array_merge($valid, ['--expected-staged-count' => '4']));
        $this->assertContains('expected_staged_count_mismatch', $mismatchPayload['blockers']);
    }

    /** @throws JsonException */
    public function test_active_or_unknown_import_state_refuses_without_writes(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedFixtureRows($supplier, 5, 2);
        $before = $this->protectedCounts();
        $run = SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'manual',
            'import_type' => 'xml',
            'status' => 'running',
        ]);

        $active = $this->failedCommandJson($this->baselineArguments($supplier));
        $this->assertSame('active_import_detected', $active['verdict']);
        $this->assertContains('active_import_detected', $active['blockers']);

        $run->update(['status' => 'unrecognized_state']);
        $unknown = $this->failedCommandJson($this->baselineArguments($supplier));
        $this->assertContains('import_state_unknown', $unknown['blockers']);
        $this->assertSame($before['supplier_import_runs'] + 1, $this->protectedCounts()['supplier_import_runs']);
    }

    /** @throws JsonException */
    public function test_unsafe_catalog_sync_flags_refuse_without_changing_configuration_or_data(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedFixtureRows($supplier, 5, 2);
        $before = $this->protectedCounts();

        foreach (['update_enabled', 'sync_all_enabled', 'auto_enabled'] as $flag) {
            config(["catalog_sync.{$flag}" => true]);
            $payload = $this->failedCommandJson($this->baselineArguments($supplier));
            $this->assertSame('unsafe_configuration', $payload['verdict']);
            $this->assertContains('unsafe_catalog_sync_flags', $payload['blockers']);
            $this->assertTrue($payload['global_safety_flags']['catalog_sync_'.$flag]);
            config(["catalog_sync.{$flag}" => false]);
        }

        config(['catalog_sync.create_enabled' => false]);
        $payload = $this->failedCommandJson($this->baselineArguments($supplier));
        $this->assertSame('unsafe_configuration', $payload['verdict']);
        $this->assertSame($before, $this->protectedCounts());
    }

    public function test_table_output_is_safe_readable_and_contains_no_fixture_urls_or_values(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedFixtureRows($supplier, 5, 2);

        $this->assertSame(0, Artisan::call('suppliers:plan-local-source-normalization', array_merge($this->baselineArguments($supplier), [
            '--summary-only' => true,
        ])));

        $output = Artisan::output();
        $this->assertStringContainsString('Local supplier source normalization plan', $output);
        $this->assertStringContainsString('Read-only.', $output);
        $this->assertStringContainsString('Verdict:', $output);
        $this->assertStringNotContainsString('SYN-AP-001', $output);
        $this->assertStringNotContainsString('images.example.test', $output);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function commandJson(array $arguments): array
    {
        $this->assertSame(0, Artisan::call('suppliers:plan-local-source-normalization', array_merge($arguments, ['--output' => 'json'])));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function failedCommandJson(array $arguments): array
    {
        $this->assertSame(1, Artisan::call('suppliers:plan-local-source-normalization', array_merge($arguments, ['--output' => 'json'])));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function baselineArguments(Supplier $supplier): array
    {
        $fixture = base_path('tests/Fixtures/Suppliers/apcom_local_source/synthetic-apcom-products.xml');
        $stagedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->count();
        $linkedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->whereNotNull('product_id')->count();

        return [
            '--supplier' => 'apcom',
            '--source' => $fixture,
            '--source-format' => 'xml',
            '--expected-sha256' => hash_file('sha256', $fixture),
            '--full-file' => true,
            '--expected-supplier-id' => (string) $supplier->id,
            '--expected-schedule-enabled' => 'false',
            '--expected-import-enabled' => 'true',
            '--expected-schedule-type' => 'twice_daily',
            '--expected-staged-count' => (string) $stagedCount,
            '--expected-linked-count' => (string) $linkedCount,
            '--expected-unlinked-count' => (string) ($stagedCount - $linkedCount),
            '--expected-last-import-at' => '2026-06-01 10:00:00',
        ];
    }

    private function supplierWithBaseline(): Supplier
    {
        return Supplier::factory()->create([
            'company_name' => 'Synthetic APCOM',
            'slug' => 'apcom',
            'status' => 'active',
            'import_enabled' => true,
            'schedule_enabled' => false,
            'schedule_type' => 'twice_daily',
            'last_import_at' => '2026-06-01 10:00:00',
        ]);
    }

    private function stagedFixtureRows(Supplier $supplier, int $count, int $linkedCount): void
    {
        $product = $linkedCount > 0 ? Product::factory()->create() : null;

        foreach (range(1, $count) as $index) {
            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'product_id' => $index <= $linkedCount ? $product?->id : null,
                'supplier_sku' => 'FIXTURE-'.$index,
                'ean' => '1000000000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'mpn' => 'FIXTURE-MPN-'.$index,
                'name' => 'Synthetic staged product '.$index,
                'brand_name' => 'SyntheticBrand',
                'category_name' => 'Synthetic Category',
                'price' => '100.00',
                'quantity' => 1,
                'currency' => 'EUR',
                'raw_data' => ['source' => 'synthetic_fixture'],
                'payload_hash' => hash('sha256', 'synthetic-plan-'.$supplier->id.'-'.$index),
                'received_at' => now(),
                'status' => 'new',
            ]);
        }
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        return collect([
            'suppliers', 'supplier_products', 'products', 'categories', 'supplier_category_mappings',
            'canonical_product_families', 'category_product_attributes', 'product_attributes', 'attribute_values',
            'product_attribute_values', 'catalog_sync_batches', 'catalog_sync_logs', 'supplier_import_runs', 'import_jobs',
        ])->mapWithKeys(fn (string $table): array => [$table => Schema::hasTable($table) ? DB::table($table)->count() : 0])
            ->put('catalog_sync', 0)
            ->all();
    }
}
