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

class LocalSupplierSourceStagingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_read_only_and_has_no_unsafe_controls(): void
    {
        $command = Artisan::all()['suppliers:reconcile-local-source-staging'];
        $definition = $command->getDefinition();

        $this->assertStringContainsString('Read-only', (string) $command->getDescription());
        $this->assertStringContainsString('no remote', (string) $command->getDescription());
        foreach ([
            'supplier', 'source', 'source-format', 'record-path', 'semantics-profile', 'expected-sha256', 'full-file',
            'expected-supplier-id', 'expected-schedule-enabled', 'expected-import-enabled', 'expected-schedule-type',
            'expected-staged-count', 'expected-linked-count', 'expected-unlinked-count', 'expected-last-import-at',
            'output', 'summary-only', 'sample-limit', 'issue-sample-limit',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }
        foreach (['apply', 'persist', 'fix', 'repair', 'import', 'sync', 'sync-all', 'create', 'update', 'delete', 'fetch', 'schedule', 'enable', 'disable', 'dispatch', 'queue', 'download'] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
    }

    /** @throws JsonException */
    public function test_official_apcom_reconciliation_is_deterministic_read_only_and_emits_only_hashes(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedRows($supplier);
        Bus::fake();
        Http::fake();
        $before = $this->protectedCounts();

        $payload = $this->commandJson($this->arguments($supplier));

        $this->assertSame('local-supplier-source-staging-reconciliation-v1', $payload['schema_version']);
        $this->assertSame('local_source_staging_reconciliation', $payload['mode']);
        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['success']);
        $this->assertSame('apcom-official-v1', $payload['semantics_profile']['key']);
        $this->assertSame('xml.product', $payload['semantics_profile']['record_path']);
        $this->assertTrue($payload['semantics_profile']['stock_is_not_quantity']);
        $this->assertTrue($payload['semantics_profile']['partno_is_not_mpn']);
        $this->assertTrue($payload['semantics_profile']['cncode_is_not_identifier']);
        $this->assertFalse($payload['semantics_profile']['price_selection_resolved']);
        $this->assertTrue($payload['semantics_profile']['previous_quantity_to_stock_heuristic_superseded']);
        $this->assertTrue($payload['baseline_lock']['matches']);
        $this->assertSame('clear', $payload['active_import_check']['state']);
        $this->assertSame(4, $payload['source_aggregates']['source_record_count']);
        $this->assertSame(4, $payload['staging_aggregates']['row_count']);
        $this->assertSame(2, $payload['exact_supplier_sku_reconciliation']['exact_one_to_one_match_count']);
        $this->assertSame(2, $payload['exact_supplier_sku_reconciliation']['source_only_sku_count']);
        $this->assertSame(2, $payload['exact_supplier_sku_reconciliation']['staging_only_sku_count']);
        $this->assertSame(0, $payload['exact_supplier_sku_reconciliation']['staging_only_linked_row_count']);
        $this->assertSame(2, $payload['exact_supplier_sku_reconciliation']['staging_only_unlinked_row_count']);
        $this->assertSame(1, $payload['normalized_match_diagnostics']['case_whitespace_nfc_normalized_only_candidate_count']);
        $this->assertTrue($payload['normalized_match_diagnostics']['normalization_is_diagnostic_only']);
        $this->assertFalse($payload['normalized_match_diagnostics']['automatic_normalized_match_allowed']);
        $this->assertSame(2, $payload['ean_diagnostics']['cross_sku_ean_conflict_count']);
        $this->assertSame(2, $payload['source_aggregates']['stock']['zero_count']);
        $this->assertSame(2, $payload['source_aggregates']['stock']['positive_count']);
        $this->assertSame(1, $payload['source_aggregates']['eol']['one_count']);
        $this->assertSame(1, $payload['source_aggregates']['price_candidates']['dac_price']['zero_count']);
        $this->assertSame(2, $payload['source_aggregates']['price_candidates']['dac_higher_count']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertFalse($payload['persisted_feed_profile_created']);
        $this->assertFalse($payload['executable_import_config_created']);
        $this->assertFalse($payload['import_executed']);
        $this->assertFalse($payload['catalog_sync_executed']);
        $this->assertFalse($payload['links_changed']);
        $this->assertSame($before, $this->protectedCounts());
        $this->assertFalse($payload['automatic_mapping_or_import_allowed']);
        $this->assertFalse($payload['persisted_semantics_profile_created']);
        $this->assertFalse($payload['execution_or_sync_action_created']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('APCOM-SYN-001', $encoded);
        $this->assertStringNotContainsString('APCOM SYN 003', $encoded);
        $this->assertStringNotContainsString('APCOM-LEGACY-004', $encoded);
        $this->assertStringNotContainsString('1000000000001', $encoded);
        $this->assertStringNotContainsString('Synthetic official APCOM product one', $encoded);
        foreach ($payload['bounded_hash_samples']['source_only_supplier_sku_hashes'] as $hash) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        }
        foreach ($payload['bounded_hash_samples']['truncation'] as $truncated) {
            $this->assertFalse($truncated);
        }

        $bounded = $this->commandJson(array_merge($this->arguments($supplier), ['--sample-limit' => '1']));
        $this->assertTrue($bounded['bounded_hash_samples']['truncation']['source_only_supplier_sku']);
        $this->assertTrue($bounded['bounded_hash_samples']['truncation']['staging_only_supplier_sku']);
        $this->assertTrue($bounded['bounded_hash_samples']['truncation']['cross_sku_ean_conflict']);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_observed_stock_profile_continues_hashed_reconciliation_without_approving_stock_semantics(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->observedStagedRows($supplier);
        Bus::fake();
        Http::fake();
        $before = $this->protectedCounts();

        $payload = $this->commandJson($this->arguments($supplier, 'synthetic-apcom-observed-stock.xml', 'apcom-observed-stock-v1'));

        $this->assertTrue($payload['success']);
        $this->assertSame('reconciliation_requires_stock_semantics_review', $payload['verdict']);
        $this->assertSame([], $payload['blockers']);
        $this->assertContains('stock_semantics_discrepancy_requires_review', $payload['warnings']);
        $this->assertSame('apcom-observed-stock-v1', $payload['selected_semantics_profile']);
        $this->assertSame('apcom-official-v1', $payload['official_semantics_profile']);
        $this->assertSame('apcom-observed-stock-v1', $payload['observed_semantics_profile']);
        $this->assertTrue($payload['semantics_profile']['semantics_discrepancy']);
        $this->assertSame('unresolved', $payload['semantics_profile']['semantic_resolution']);
        $this->assertNull($payload['semantics_profile']['quantity_path']);
        $this->assertNull($payload['semantics_profile']['availability_path']);
        $this->assertTrue($payload['semantics_profile']['stock_is_not_quantity']);
        $this->assertTrue($payload['semantics_profile']['stock_is_not_binary_availability']);

        $stock = $payload['observed_stock_analysis'];
        $this->assertSame(4, $stock['total_records']);
        $this->assertSame(4, $stock['elements_present']);
        $this->assertSame(4, $stock['numeric_count']);
        $this->assertSame(4, $stock['integer_count']);
        $this->assertSame(1, $stock['zero_count']);
        $this->assertSame(1, $stock['one_count']);
        $this->assertSame(2, $stock['greater_than_one_count']);
        $this->assertSame(3, $stock['positive_count']);
        $this->assertSame(4, $stock['distinct_numeric_value_count']);
        $this->assertSame('0', $stock['minimum_numeric_value']);
        $this->assertSame('100', $stock['maximum_numeric_value']);
        $this->assertFalse($stock['official_binary_semantics_match']);
        $this->assertTrue($stock['observed_numeric_contract_valid']);
        $this->assertSame(1, $payload['source_aggregates']['stock_eol_combinations']['stock_greater_than_one_eol_zero']);
        $this->assertSame(1, $payload['source_aggregates']['stock_eol_combinations']['stock_greater_than_one_eol_one']);

        $this->assertTrue($payload['stock_semantics_discrepancy']['detected']);
        $this->assertSame('binary_0_1_availability', $payload['stock_semantics_discrepancy']['official_claim']);
        $this->assertSame('non_negative_integer_numeric', $payload['stock_semantics_discrepancy']['observed_contract']);
        $this->assertSame('unresolved', $payload['stock_semantics_discrepancy']['semantic_resolution']);
        $this->assertFalse($payload['stock_semantics_discrepancy']['quantity_mapping_allowed']);
        $this->assertFalse($payload['stock_semantics_discrepancy']['availability_mapping_allowed']);
        $this->assertFalse($payload['stock_semantics_discrepancy']['reconciliation_blocked']);
        $this->assertTrue($payload['reconciliation_continued_despite_stock_semantics_discrepancy']);
        $this->assertTrue($payload['unresolved_quantity']);
        $this->assertTrue($payload['unresolved_availability']);
        $this->assertSame('invalid_stock_semantics_detected', $payload['previous_strict_failure_reference']['blocker']);
        $this->assertSame(2, $payload['exact_supplier_sku_reconciliation']['exact_one_to_one_match_count']);
        $this->assertSame(2, $payload['exact_supplier_sku_reconciliation']['source_only_sku_count']);
        $this->assertSame(1, $payload['exact_supplier_sku_reconciliation']['staging_only_sku_count']);
        $this->assertSame(1, $payload['exact_supplier_sku_reconciliation']['matched_linked_staging_row_count']);
        $this->assertSame(1, $payload['exact_supplier_sku_reconciliation']['matched_unlinked_staging_row_count']);
        $this->assertSame(1, $payload['exact_supplier_sku_reconciliation']['staging_only_unlinked_row_count']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());
        $this->assertFalse($payload['persisted_feed_profile_created']);
        $this->assertFalse($payload['executable_import_config_created']);
        $this->assertFalse($payload['import_executed']);
        $this->assertFalse($payload['catalog_sync_executed']);
        $this->assertFalse($payload['links_changed']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('APCOM-OBS-001', $encoded);
        $this->assertStringNotContainsString('2000000000001', $encoded);
        $this->assertStringNotContainsString('Synthetic observed product one', $encoded);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_observed_stock_profile_blocks_invalid_numeric_values_and_invalid_eol_without_mutation(): void
    {
        $supplier = $this->supplierWithBaseline();
        Bus::fake();
        Http::fake();
        $before = $this->protectedCounts();

        foreach ([
            ['synthetic-apcom-observed-stock-blank.xml', 'invalid_observed_stock_semantics_detected'],
            ['synthetic-apcom-observed-stock-non-numeric.xml', 'invalid_observed_stock_semantics_detected'],
            ['synthetic-apcom-observed-stock-fractional.xml', 'invalid_observed_stock_semantics_detected'],
            ['synthetic-apcom-observed-stock-negative.xml', 'invalid_observed_stock_semantics_detected'],
            ['synthetic-apcom-observed-stock-invalid-eol.xml', 'invalid_eol_semantics_detected'],
        ] as [$fixture, $blocker]) {
            $payload = $this->failedCommandJson($this->arguments($supplier, $fixture, 'apcom-observed-stock-v1'));

            $this->assertSame('audit_failed', $payload['verdict']);
            $this->assertContains($blocker, $payload['blockers']);
            $this->assertSame(0, array_sum($payload['records_changed']));
            $this->assertSame($before, $this->protectedCounts());
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_observed_stock_profile_does_not_impose_a_hard_maximum_of_one_hundred(): void
    {
        $supplier = $this->supplierWithBaseline();
        Bus::fake();
        Http::fake();
        $before = $this->protectedCounts();

        $payload = $this->commandJson($this->arguments($supplier, 'synthetic-apcom-observed-stock-above-100.xml', 'apcom-observed-stock-v1'));

        $this->assertTrue($payload['success']);
        $this->assertSame('101', $payload['observed_stock_analysis']['maximum_numeric_value']);
        $this->assertTrue($payload['observed_stock_analysis']['observed_numeric_contract_valid']);
        $this->assertSame($before, $this->protectedCounts());
        $this->assertSame(0, array_sum($payload['records_changed']));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_duplicate_sku_invalid_stock_and_invalid_price_fail_safely_without_mutation(): void
    {
        $supplier = $this->supplierWithBaseline();
        Bus::fake();
        Http::fake();
        $before = $this->protectedCounts();

        foreach ([
            ['synthetic-apcom-official-duplicate-sku.xml', 'duplicate_authoritative_supplier_sku_detected'],
            ['synthetic-apcom-official-blank-sku.xml', 'blank_authoritative_supplier_sku_detected'],
            ['synthetic-apcom-official-invalid-stock.xml', 'invalid_stock_semantics_detected'],
            ['synthetic-apcom-official-invalid-price.xml', 'invalid_price_candidate_detected'],
            ['synthetic-apcom-official-missing-eol.xml', 'official_semantics_required_field_missing'],
        ] as [$fixture, $blocker]) {
            $payload = $this->failedCommandJson($this->arguments($supplier, $fixture));
            $this->assertSame('audit_failed', $payload['verdict']);
            $this->assertContains($blocker, $payload['blockers']);
            $this->assertSame(0, array_sum($payload['records_changed']));
            $this->assertSame($before, $this->protectedCounts());
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @throws JsonException */
    public function test_unknown_profile_baseline_mismatch_active_import_and_unsafe_flags_refuse_safely(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedRows($supplier);
        $valid = $this->arguments($supplier);

        $unknown = $this->failedCommandJson(array_merge($valid, ['--semantics-profile' => 'unknown-v1']));
        $this->assertSame('unknown_semantics_profile', $unknown['verdict']);
        $this->assertContains('unknown_semantics_profile', $unknown['blockers']);

        $mismatch = $this->failedCommandJson(array_merge($valid, ['--expected-staged-count' => '99']));
        $this->assertSame('baseline_state_mismatch', $mismatch['verdict']);
        $this->assertContains('expected_staged_count_mismatch', $mismatch['blockers']);

        SupplierImportRun::query()->create(['supplier_id' => $supplier->id, 'trigger_type' => 'manual', 'import_type' => 'xml', 'status' => 'running']);
        $active = $this->failedCommandJson($valid);
        $this->assertSame('active_import_detected', $active['verdict']);
        $this->assertContains('active_import_detected', $active['blockers']);

        SupplierImportRun::query()->update(['status' => 'unknown_state']);
        $unknown = $this->failedCommandJson($valid);
        $this->assertContains('import_state_unknown', $unknown['blockers']);

        SupplierImportRun::query()->update(['status' => 'completed']);
        config(['catalog_sync.update_enabled' => true]);
        $unsafe = $this->failedCommandJson($valid);
        $this->assertSame('unsafe_configuration', $unsafe['verdict']);
        $this->assertContains('unsafe_catalog_sync_flags', $unsafe['blockers']);
        config(['catalog_sync.update_enabled' => false]);
    }

    /** @throws JsonException */
    public function test_remote_sources_sha_mismatch_and_record_path_mismatch_are_rejected_without_values(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedRows($supplier);
        $valid = $this->arguments($supplier);

        foreach ([
            array_merge($valid, ['--source' => 'https://example.invalid/apcom.xml']),
            array_merge($valid, ['--expected-sha256' => str_repeat('a', 64)]),
            array_merge($valid, ['--record-path' => 'xml.other']),
            array_diff_key($valid, ['--full-file' => true]),
        ] as $arguments) {
            $payload = $this->failedCommandJson($arguments);
            $this->assertSame(0, array_sum($payload['records_changed']));
            $this->assertStringNotContainsString('example.invalid', json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }

    public function test_table_output_is_safe_and_does_not_emit_synthetic_identifiers_or_urls(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedRows($supplier);

        $this->assertSame(0, Artisan::call('suppliers:reconcile-local-source-staging', array_merge($this->arguments($supplier), ['--summary-only' => true])));
        $output = Artisan::output();
        $this->assertStringContainsString('Local supplier source-to-staging reconciliation', $output);
        $this->assertStringContainsString('Read-only.', $output);
        $this->assertStringNotContainsString('APCOM-SYN-001', $output);
        $this->assertStringNotContainsString('synthetic-image-one.jpg', $output);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function commandJson(array $arguments): array
    {
        $this->assertSame(0, Artisan::call('suppliers:reconcile-local-source-staging', array_merge($arguments, ['--output' => 'json'])));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function failedCommandJson(array $arguments): array
    {
        $this->assertSame(1, Artisan::call('suppliers:reconcile-local-source-staging', array_merge($arguments, ['--output' => 'json'])));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function arguments(Supplier $supplier, string $fixture = 'synthetic-apcom-official.xml', string $semanticsProfile = 'apcom-official-v1'): array
    {
        $source = base_path('tests/Fixtures/Suppliers/apcom_official_semantics/'.$fixture);
        $stagedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->count();
        $linkedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->whereNotNull('product_id')->count();

        return [
            '--supplier' => 'apcom',
            '--source' => $source,
            '--source-format' => 'xml',
            '--semantics-profile' => $semanticsProfile,
            '--expected-sha256' => hash_file('sha256', $source),
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

    private function stagedRows(Supplier $supplier): void
    {
        $product = Product::factory()->create();
        foreach ([
            ['APCOM-SYN-001', '1000000000001', $product->id],
            ['APCOM-SYN-002', '', null],
            ['apcom syn 003', '1000000000003', null],
            ['APCOM-LEGACY-004', '1000000000004', null],
        ] as $index => [$sku, $ean, $productId]) {
            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'product_id' => $productId,
                'supplier_sku' => $sku,
                'ean' => $ean,
                'name' => 'Synthetic staged product '.($index + 1),
                'brand_name' => 'SyntheticBrand',
                'category_name' => 'Synthetic Category',
                'price' => '100.00',
                'quantity' => 1,
                'currency' => 'EUR',
                'raw_data' => ['synthetic' => true],
                'payload_hash' => hash('sha256', 'synthetic-reconciliation-'.$supplier->id.'-'.$index),
                'received_at' => now(),
                'status' => 'new',
            ]);
        }
    }

    private function observedStagedRows(Supplier $supplier): void
    {
        $product = Product::factory()->create();
        foreach ([
            ['APCOM-OBS-001', '2000000000001', $product->id],
            ['APCOM-OBS-002', '2000000000999', null],
            ['APCOM-OBS-STAGING-ONLY', '2000000000099', null],
        ] as $index => [$sku, $ean, $productId]) {
            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'product_id' => $productId,
                'supplier_sku' => $sku,
                'ean' => $ean,
                'name' => 'Synthetic observed staged product '.($index + 1),
                'brand_name' => 'SyntheticBrand',
                'category_name' => 'Synthetic Category',
                'price' => '100.00',
                'quantity' => 1,
                'currency' => 'EUR',
                'raw_data' => ['synthetic' => true],
                'payload_hash' => hash('sha256', 'synthetic-observed-reconciliation-'.$supplier->id.'-'.$index),
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
