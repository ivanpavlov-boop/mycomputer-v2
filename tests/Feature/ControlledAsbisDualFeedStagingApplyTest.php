<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\AsbisApplyReadinessAuditService;
use App\Services\Suppliers\AsbisCandidateFingerprintService;
use App\Services\Suppliers\AsbisStagingCandidatePayloadBuilder;
use App\Services\Suppliers\AsbisStagingPayloadSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ControlledAsbisDualFeedStagingApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_with_apply_controls_but_no_forbidden_modes(): void
    {
        $definition = Artisan::all()['suppliers:controlled-asbis-dual-feed-staging-import']->getDefinition();

        foreach ([
            'supplier',
            'product-list',
            'price-avail',
            'product-list-fixture',
            'price-avail-fixture',
            'product-key',
            'price-key',
            'format',
            'batch-size',
            'apply',
            'confirm-supplier',
            'confirm-mode',
            'confirm-write-scope',
            'expected-candidate-sha256',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option), 'Missing option '.$option);
        }

        foreach (['warning-include', 'update-existing', 'sync-products', 'catalog', 'create-products', 'download-images', 'enable-schedule', 'sync-all'] as $forbidden) {
            $this->assertFalse($definition->hasOption($forbidden), 'Forbidden option was added: '.$forbidden);
        }
    }

    public function test_default_dry_run_builds_ready_create_candidates_without_writes(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => false,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $asbis = Supplier::factory()->create([
            'company_name' => 'ASBIS',
            'slug' => 'asbis',
            'schedule_enabled' => false,
        ]);
        AvailabilityStatus::query()->create([
            'code' => 'in_stock',
            'name' => 'In Stock',
            'color' => 'green',
            'icon' => 'check',
            'is_active' => true,
            'allow_purchase' => true,
            'show_stock_quantity' => true,
        ]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $payload = $this->commandJson($asbis, $productPath, $pricePath);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('supplier_products_only', $payload['write_scope']);
        $this->assertTrue($payload['create_only']);
        $this->assertFalse($payload['feature_enabled']);
        $this->assertFalse($payload['can_apply']);
        $this->assertContains('dry_run_mode', $payload['refusal_reasons']);
        $this->assertSame(1, $payload['calculated_ready_count']);
        $this->assertSame(0, $payload['inserted_count']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame(0, SupplierProduct::query()->where('supplier_id', $asbis->id)->count());
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('categories')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    public function test_apply_requires_feature_flag_and_explicit_fingerprint_confirmations(): void
    {
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => false,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $dryRun = $this->commandJson($asbis, $productPath, $pricePath);
            $payload = $this->commandJson($asbis, $productPath, $pricePath, [
                '--apply' => true,
                '--confirm-supplier' => 'asbis',
                '--confirm-mode' => 'create-only',
                '--confirm-write-scope' => 'supplier_products-only',
                '--expected-product-list-sha256' => $dryRun['source_fingerprints']['product_list_sha256'],
                '--expected-price-avail-sha256' => $dryRun['source_fingerprints']['price_avail_sha256'],
                '--expected-ready-count' => $dryRun['calculated_ready_count'],
                '--expected-candidate-sha256' => $dryRun['ready_to_create_candidate_set_sha256'],
                '--expected-asbis-staged-count' => 0,
            ]);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertFalse($payload['success']);
        $this->assertContains('apply_feature_disabled', $payload['refusal_reasons']);
        $this->assertSame(0, $payload['inserted_count']);
        $this->assertSame(0, SupplierProduct::query()->where('supplier_id', $asbis->id)->count());
    }

    public function test_old_candidate_hash_is_refused_after_schema_version_bump(): void
    {
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => true,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $dryRun = $this->commandJson($asbis, $productPath, $pricePath);
            $payload = $this->commandJson($asbis, $productPath, $pricePath, [
                '--apply' => true,
                '--confirm-supplier' => 'asbis',
                '--confirm-mode' => 'create-only',
                '--confirm-write-scope' => 'supplier_products-only',
                '--expected-product-list-sha256' => $dryRun['source_fingerprints']['product_list_sha256'],
                '--expected-price-avail-sha256' => $dryRun['source_fingerprints']['price_avail_sha256'],
                '--expected-ready-count' => 1,
                '--expected-candidate-sha256' => str_repeat('0', 64),
                '--expected-asbis-staged-count' => 0,
            ]);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertFalse($payload['success']);
        $this->assertContains('candidate_set_fingerprint_mismatch', $payload['refusal_reasons']);
        $this->assertSame(0, SupplierProduct::query()->where('supplier_id', $asbis->id)->count());
    }

    public function test_enabled_apply_inserts_only_ready_create_rows_and_preserves_protected_tables(): void
    {
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => true,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        AvailabilityStatus::query()->create([
            'code' => 'in_stock',
            'name' => 'In Stock',
            'color' => 'green',
            'icon' => 'check',
            'is_active' => true,
            'allow_purchase' => true,
            'show_stock_quantity' => true,
        ]);
        $protectedBefore = $this->protectedCounts();
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $dryRun = $this->commandJson($asbis, $productPath, $pricePath);
            $payload = $this->commandJson($asbis, $productPath, $pricePath, [
                '--apply' => true,
                '--confirm-supplier' => 'asbis',
                '--confirm-mode' => 'create-only',
                '--confirm-write-scope' => 'supplier_products-only',
                '--expected-product-list-sha256' => $dryRun['source_fingerprints']['product_list_sha256'],
                '--expected-price-avail-sha256' => $dryRun['source_fingerprints']['price_avail_sha256'],
                '--expected-ready-count' => 1,
                '--expected-candidate-sha256' => $dryRun['ready_to_create_candidate_set_sha256'],
                '--expected-asbis-staged-count' => 0,
                '--batch-size' => 1,
            ]);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $staged = SupplierProduct::query()->where('supplier_id', $asbis->id)->firstOrFail();
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['transaction_committed']);
        $this->assertSame(1, $payload['inserted_count']);
        $this->assertSame(1, $payload['batches']);
        $this->assertSame(0, $payload['total_staged_before']);
        $this->assertSame(1, $payload['total_staged_after']);
        $this->assertSame('ASBIS-CANDIDATE-001', $staged->supplier_sku);
        $this->assertSame('000000000001', $staged->ean);
        $this->assertSame('10.50', (string) $staged->price);
        $this->assertSame('in_stock', $staged->external_availability_status);
        $this->assertSame('asbis-dual-feed-staging-candidate-v2', $payload['candidate_payload_schema_version']);
        $this->assertTrue($payload['payload_schema_compatible']);
        $this->assertSame(0, $payload['payload_schema_compatibility']['truncated_name_count']);
        $this->assertSame($dryRun['source_fingerprints']['product_list_sha256'], $staged->raw_data['product_list_sha256']);
        $this->assertSame($dryRun['source_fingerprints']['price_avail_sha256'], $staged->raw_data['price_avail_sha256']);
        $this->assertNull($staged->product_id);
        $this->assertSame('new', $staged->status);
        $this->assertSame($protectedBefore, $this->protectedCounts());
        $this->assertSame(0, DB::table('products')->count());
        Http::assertNothingSent();
    }

    public function test_second_run_reports_existing_asbis_staging_conflict_without_duplicate_or_update(): void
    {
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => true,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $dryRun = $this->commandJson($asbis, $productPath, $pricePath);
            $arguments = [
                '--apply' => true,
                '--confirm-supplier' => 'asbis',
                '--confirm-mode' => 'create-only',
                '--confirm-write-scope' => 'supplier_products-only',
                '--expected-product-list-sha256' => $dryRun['source_fingerprints']['product_list_sha256'],
                '--expected-price-avail-sha256' => $dryRun['source_fingerprints']['price_avail_sha256'],
                '--expected-ready-count' => 1,
                '--expected-candidate-sha256' => $dryRun['ready_to_create_candidate_set_sha256'],
                '--expected-asbis-staged-count' => 0,
            ];
            $first = $this->commandJson($asbis, $productPath, $pricePath, $arguments);
            $second = $this->commandJson($asbis, $productPath, $pricePath, $arguments);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertContains('existing_asbis_staging_conflict', $second['refusal_reasons']);
        $this->assertSame(1, SupplierProduct::query()->where('supplier_id', $asbis->id)->count());
        $this->assertSame(0, $second['updated_count']);
    }

    public function test_candidate_fingerprint_is_order_independent_and_changes_with_write_fields(): void
    {
        $service = app(AsbisCandidateFingerprintService::class);
        $first = [
            ['supplier_sku' => 'B', 'price' => '20.00', 'payload_hash' => 'b', 'raw_data' => ['product_list_sha256' => 'source-a']],
            ['supplier_sku' => 'A', 'price' => '10.00', 'payload_hash' => 'a', 'raw_data' => ['product_list_sha256' => 'source-a']],
        ];
        $second = array_reverse($first);
        $changed = $first;
        $changed[0]['price'] = '21.00';
        $sourceRehashed = array_map(fn (array $row): array => [
            ...$row,
            'raw_data' => ['product_list_sha256' => 'different-file-order-hash'],
            'payload_hash' => 'different-derived-hash',
        ], $first);

        $this->assertSame($service->fingerprint($first), $service->fingerprint($second));
        $this->assertSame($service->fingerprint($first), $service->fingerprint($sourceRehashed));
        $this->assertNotSame($service->fingerprint($first), $service->fingerprint($changed));
    }

    public function test_mysql_compatibility_normalizes_unicode_names_and_preserves_full_name(): void
    {
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => true,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        AvailabilityStatus::query()->create([
            'code' => 'in_stock',
            'name' => 'In Stock',
            'color' => 'green',
            'icon' => 'check',
            'is_active' => true,
            'allow_purchase' => true,
            'show_stock_quantity' => true,
        ]);
        $fullName = str_repeat('Л', 325);
        [$productPath, $pricePath] = $this->writeFixturesWithName($fullName);

        try {
            $dryRun = $this->commandJson($asbis, $productPath, $pricePath);
            $payload = $this->commandJson($asbis, $productPath, $pricePath, [
                '--apply' => true,
                '--confirm-supplier' => 'asbis',
                '--confirm-mode' => 'create-only',
                '--confirm-write-scope' => 'supplier_products-only',
                '--expected-product-list-sha256' => $dryRun['source_fingerprints']['product_list_sha256'],
                '--expected-price-avail-sha256' => $dryRun['source_fingerprints']['price_avail_sha256'],
                '--expected-ready-count' => 1,
                '--expected-candidate-sha256' => $dryRun['ready_to_create_candidate_set_sha256'],
                '--expected-asbis-staged-count' => 0,
            ]);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $staged = SupplierProduct::query()->where('supplier_id', $asbis->id)->firstOrFail();
        $this->assertTrue($dryRun['payload_schema_compatible']);
        $this->assertSame(1, $dryRun['payload_schema_compatibility']['truncated_name_count']);
        $this->assertSame(325, $dryRun['payload_schema_compatibility']['maximum_original_name_length']);
        $this->assertSame(255, $dryRun['payload_schema_compatibility']['maximum_staged_name_length']);
        $this->assertTrue($payload['success']);
        $this->assertSame(255, mb_strlen($staged->name, 'UTF-8'));
        $this->assertSame(mb_substr($fullName, 0, 255, 'UTF-8'), $staged->name);
        $this->assertSame($fullName, $staged->raw_data['original_name']);
        $this->assertSame(325, $staged->raw_data['original_name_length']);
        $this->assertTrue($staged->raw_data['name_was_truncated']);
        $this->assertSame(255, $staged->raw_data['staged_name_length']);
        $this->assertSame(255, $staged->raw_data['staged_name_limit']);
        $this->assertSame('new', $staged->status);
        $this->assertNull($staged->product_id);
    }

    public function test_payload_schema_validator_rejects_overflow_unknown_and_invalid_values(): void
    {
        $validator = app(AsbisStagingPayloadSchemaValidator::class);
        $valid = $this->validPayload();

        $overflowCases = [
            ['supplier_sku' => str_repeat('S', 256), 'field' => 'supplier_sku'],
            ['ean' => str_repeat('1', 256), 'field' => 'ean'],
            ['mpn' => str_repeat('M', 256), 'field' => 'mpn'],
            ['currency' => 'EURO', 'field' => 'currency'],
            ['brand_name' => str_repeat('B', 256), 'field' => 'brand_name'],
            ['category_name' => str_repeat('C', 256), 'field' => 'category_name'],
        ];

        foreach ($overflowCases as $case) {
            $result = $validator->validate([$this->validPayload($case)]);
            $this->assertFalse($result['payload_schema_compatible']);
            $this->assertGreaterThan(0, $result['field_length_violation_counts'][$case['field']] ?? 0);
        }

        $invalidCases = [
            ['price' => '12345678901.00', 'counter' => 'invalid_decimal_count'],
            ['quantity' => -1, 'counter' => 'invalid_unsigned_integer_count'],
            ['availability_status_id' => 999999, 'counter' => 'invalid_availability_status_id_count'],
            ['raw_data' => ['invalid_utf8' => "\xB1"], 'counter' => 'invalid_json_count'],
            ['unexpected_payload_field' => 'blocked', 'counter' => 'unknown_payload_fields'],
        ];

        foreach ($invalidCases as $case) {
            $result = $validator->validate([$this->validPayload(array_diff_key($case, ['counter' => true]))]);
            $this->assertFalse($result['payload_schema_compatible']);
            $value = $case['counter'] === 'unknown_payload_fields'
                ? $result[$case['counter']]
                : $result[$case['counter']];
            $this->assertNotEmpty($value);
        }

        $this->assertSame(0, $validator->validate([$valid])['invalid_json_count']);
    }

    public function test_name_metadata_and_fingerprint_are_deterministic_for_boundary_lengths(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);
        $builder = app(AsbisStagingCandidatePayloadBuilder::class);
        $fingerprint = app(AsbisCandidateFingerprintService::class);
        $sourceFingerprints = ['product_list_sha256' => 'product', 'price_avail_sha256' => 'price'];

        foreach ([255, 256, 325] as $length) {
            $name = str_repeat('X', $length);
            $candidate = $builder->build([$this->classifiedRow($name)], $supplier, $sourceFingerprints)[0];
            $expectedLength = min($length, 255);
            $this->assertSame($expectedLength, mb_strlen($candidate['name'], 'UTF-8'));
            $this->assertSame($length, $candidate['raw_data']['original_name_length']);
            $this->assertSame($length > 255, $candidate['raw_data']['name_was_truncated']);
            $this->assertSame($expectedLength, $candidate['raw_data']['staged_name_length']);
        }

        $prefix = str_repeat('P', 255);
        $first = $builder->build([$this->classifiedRow($prefix.'A')], $supplier, $sourceFingerprints)[0];
        $second = $builder->build([$this->classifiedRow($prefix.'B')], $supplier, $sourceFingerprints)[0];
        $legacyStatus = $first;
        $legacyStatus['status'] = 'pending_review';

        $this->assertSame('asbis-dual-feed-staging-candidate-v2', AsbisCandidateFingerprintService::SCHEMA_VERSION);
        $this->assertNotSame($fingerprint->fingerprint([$first]), $fingerprint->fingerprint([$second]));
        $this->assertNotSame($fingerprint->fingerprint([$first]), $fingerprint->fingerprint([$legacyStatus]));
    }

    public function test_asbis_transaction_diagnostics_are_safe_on_json_prepare_failure(): void
    {
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => true,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $audit = app(AsbisApplyReadinessAuditService::class)->run([
                'supplier' => $asbis->slug,
                'product_list_fixture' => $productPath,
                'price_avail_fixture' => $pricePath,
                'product_key' => 'ProductCode',
                'price_key' => 'WIC',
                'mode' => 'controlled_asbis_dual_feed_staging_import',
                'full_file' => true,
                'include_candidate_payloads' => true,
            ]);
            $audit['candidate_payloads'][0]['raw_data'] = ['invalid_utf8' => "\xB1"];
            $auditMock = \Mockery::mock(AsbisApplyReadinessAuditService::class);
            $auditMock->shouldReceive('run')->once()->andReturn($audit);
            app()->instance(AsbisApplyReadinessAuditService::class, $auditMock);

            $validatorMock = \Mockery::mock(AsbisStagingPayloadSchemaValidator::class);
            $validatorMock->shouldReceive('validate')->once()->andReturn([
                'payload_schema_compatible' => true,
                'candidate_count' => 1,
                'truncated_name_count' => 0,
                'maximum_original_name_length' => 15,
                'maximum_staged_name_length' => 15,
                'field_length_violation_counts' => [],
                'field_length_violation_samples' => [],
                'unknown_payload_fields' => [],
                'missing_schema_columns' => [],
                'invalid_json_count' => 0,
                'invalid_availability_status_id_count' => 0,
                'nullability_violation_count' => 0,
                'invalid_decimal_count' => 0,
                'invalid_unsigned_integer_count' => 0,
            ]);
            app()->instance(AsbisStagingPayloadSchemaValidator::class, $validatorMock);

            $fingerprintMock = \Mockery::mock(AsbisCandidateFingerprintService::class);
            $fingerprintMock->shouldReceive('fingerprint')->andReturn('fingerprint');
            app()->instance(AsbisCandidateFingerprintService::class, $fingerprintMock);

            $payload = $this->commandJson($asbis, $productPath, $pricePath, [
                '--apply' => true,
                '--confirm-supplier' => 'asbis',
                '--confirm-mode' => 'create-only',
                '--confirm-write-scope' => 'supplier_products-only',
                '--expected-product-list-sha256' => $audit['source_fingerprints']['product_list_sha256'],
                '--expected-price-avail-sha256' => $audit['source_fingerprints']['price_avail_sha256'],
                '--expected-ready-count' => 1,
                '--expected-candidate-sha256' => 'fingerprint',
                '--expected-asbis-staged-count' => 0,
            ]);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertFalse($payload['success']);
        $this->assertContains('transaction_failed', $payload['refusal_reasons']);
        $this->assertSame('database_json_encoding_failure', $payload['failure_diagnostics']['diagnostic_code']);
        $this->assertSame('prepare_batch', $payload['failure_diagnostics']['transaction_stage']);
        $this->assertSame(1, $payload['attempted_insert_count']);
        $this->assertSame(1, $payload['attempted_batches']);
        $this->assertSame(0, $payload['inserted_count']);
        $this->assertSame(0, $payload['committed_batches']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertArrayNotHasKey('message', $payload['failure_diagnostics']);
        $this->assertSame(0, SupplierProduct::query()->where('supplier_id', $asbis->id)->count());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function commandJson(Supplier $supplier, string $productPath, string $pricePath, array $extra = []): array
    {
        $arguments = array_merge([
            '--supplier' => $supplier->slug,
            '--product-list-fixture' => $productPath,
            '--price-avail-fixture' => $pricePath,
            '--format' => 'json',
            '--sample-limit' => 2,
        ], $extra);
        Artisan::call('suppliers:controlled-asbis-dual-feed-staging-import', $arguments);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function writeFixtures(): array
    {
        $productPath = tempnam(sys_get_temp_dir(), 'asbis-product-');
        $pricePath = tempnam(sys_get_temp_dir(), 'asbis-price-');

        file_put_contents($productPath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ProductCatalog>
    <Product>
        <ProductCode>ASBIS-CANDIDATE-001</ProductCode>
        <Vendor>Candidate Brand</Vendor>
        <ProductCategory>Laptops</ProductCategory>
        <ProductDescription>Candidate laptop</ProductDescription>
    </Product>
</ProductCatalog>
XML);
        file_put_contents($pricePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CONTENT>
    <PRICE>
        <WIC>ASBIS-CANDIDATE-001</WIC>
        <MY_PRICE>10.50</MY_PRICE>
        <CURRENCY_CODE>EUR</CURRENCY_CODE>
        <AVAIL>In Stock</AVAIL>
        <EAN>000000000001</EAN>
        <DESCRIPTION>Candidate laptop price description</DESCRIPTION>
    </PRICE>
</CONTENT>
XML);

        return [$productPath, $pricePath];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function writeFixturesWithName(string $name): array
    {
        $productPath = tempnam(sys_get_temp_dir(), 'asbis-product-long-');
        $pricePath = tempnam(sys_get_temp_dir(), 'asbis-price-long-');
        $escapedName = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        file_put_contents($productPath, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ProductCatalog><Product><ProductCode>ASBIS-LONG-001</ProductCode><Vendor>Candidate Brand</Vendor><ProductCategory>Laptops</ProductCategory><ProductDescription>{$escapedName}</ProductDescription></Product></ProductCatalog>");
        file_put_contents($pricePath, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<CONTENT><PRICE><WIC>ASBIS-LONG-001</WIC><MY_PRICE>10.50</MY_PRICE><CURRENCY_CODE>EUR</CURRENCY_CODE><AVAIL>In Stock</AVAIL><EAN>000000000002</EAN><DESCRIPTION>{$escapedName}</DESCRIPTION></PRICE></CONTENT>");

        return [$productPath, $pricePath];
    }

    /**
     * @return array<string, mixed>
     */
    private function classifiedRow(string $name): array
    {
        return [
            'readiness_state' => 'ready_to_create',
            'supplier_sku' => 'ASBIS-TEST-001',
            'ean_gtin' => '000000000001',
            'mpn' => 'ASBIS-TEST-MPN',
            'name' => $name,
            'brand' => 'Brand',
            'category' => 'Category',
            'price' => '10.50',
            'currency' => 'EUR',
            'availability' => 'in_stock',
            'raw_availability' => 'In Stock',
            'stock' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => 1,
            'supplier_feed_id' => null,
            'product_id' => null,
            'supplier_sku' => 'ASBIS-TEST-001',
            'ean' => '000000000001',
            'mpn' => 'ASBIS-TEST-MPN',
            'name' => 'Test product',
            'brand_name' => 'Brand',
            'category_name' => 'Category',
            'price' => '10.50',
            'supplier_price_raw' => '10.50',
            'recommended_price' => null,
            'quantity' => 1,
            'external_availability_status' => 'in_stock',
            'external_availability_label' => 'In Stock',
            'availability_status_id' => null,
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => str_repeat('a', 64),
            'received_at' => now(),
            'synced_at' => null,
            'status' => 'new',
            'mapping_notes' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    /**
     * @return array<string, int>
     */
    private function protectedCounts(): array
    {
        return collect([
            'products',
            'suppliers',
            'categories',
            'supplier_category_mappings',
            'canonical_product_families',
            'category_product_attributes',
            'product_attributes',
            'attribute_values',
            'product_attribute_values',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
