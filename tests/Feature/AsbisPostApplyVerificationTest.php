<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\AsbisApplyReadinessAuditService;
use App\Services\Suppliers\AsbisCandidateFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JsonException;
use Tests\TestCase;

class AsbisPostApplyVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_read_only_and_has_no_write_options(): void
    {
        $command = Artisan::all()['suppliers:audit-asbis-post-apply-verification'];
        $definition = $command->getDefinition();

        foreach ([
            'supplier',
            'product-list',
            'price-avail',
            'product-list-fixture',
            'price-avail-fixture',
            'product-key',
            'price-key',
            'expected-product-list-sha256',
            'expected-price-avail-sha256',
            'expected-candidate-sha256',
            'expected-ready-count',
            'expected-asbis-staged-count',
            'expected-total-staged-count',
            'format',
            'summary-only',
            'sample-limit',
            'issue-sample-limit',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option), 'Missing option '.$option);
        }

        foreach (['apply', 'fix', 'repair', 'sync', 'sync-all', 'create', 'update', 'delete', 'unlink', 'rebuild', 'confirm-supplier', 'enable-schedule', 'download-images'] as $option) {
            $this->assertFalse($definition->hasOption($option), 'Unexpected write option '.$option);
        }

        $this->assertStringContainsString('read-only', strtolower((string) $command->getDescription()));
    }

    /** @throws JsonException */
    public function test_happy_path_verifies_exact_v2_rows_without_mutation(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();
        $this->safeConfig();
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
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $audit = $this->sourceAudit($asbis, $productPath, $pricePath);
            foreach ($audit['candidate_payloads'] as $payload) {
                SupplierProduct::query()->create([...$payload, 'received_at' => now()]);
            }
            $before = $this->protectedCounts();
            $payload = $this->runVerification($asbis, $productPath, $pricePath, $audit);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertTrue($payload['verification_passed']);
        $this->assertSame('verified', $payload['verdict']);
        $this->assertSame(AsbisCandidateFingerprintService::SCHEMA_VERSION, $payload['candidate_payload_schema_version']);
        $this->assertSame(1, $payload['calculated_candidate_count']);
        $this->assertSame(1, $payload['sku_reconciliation']['source_candidate_sku_count']);
        $this->assertSame(0, $payload['row_reconciliation']['field_mismatch_counts'] ? array_sum($payload['row_reconciliation']['field_mismatch_counts']) : 0);
        $this->assertSame(0, $payload['provenance_verification']['mismatch_count']);
        $this->assertSame(0, $payload['records_changed']['products']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
        $this->assertSame($before, $this->protectedCounts());
        $this->assertSame([], $payload['issue_counts']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /** @throws JsonException */
    public function test_staged_price_mismatch_fails_without_repair_or_mutation(): void
    {
        $this->safeConfig();
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $audit = $this->sourceAudit($asbis, $productPath, $pricePath);
            SupplierProduct::query()->create([...$audit['candidate_payloads'][0], 'received_at' => now(), 'price' => '99.99']);
            $before = $this->protectedCounts();
            $payload = $this->runVerification($asbis, $productPath, $pricePath, $audit);
            $after = $this->protectedCounts();
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertFalse($payload['verification_passed']);
        $this->assertSame('candidate_mismatch', $payload['verdict']);
        $this->assertGreaterThan(0, $payload['row_reconciliation']['price_mismatch_count']);
        $this->assertArrayHasKey('canonical_row_mismatch', $payload['issue_counts']);
        $this->assertSame(1, SupplierProduct::query()->where('supplier_id', $asbis->id)->count());
        $this->assertSame($before, $after);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
    }

    /** @throws JsonException */
    public function test_source_and_expected_database_mismatches_fail_safely(): void
    {
        $this->safeConfig();
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $audit = $this->sourceAudit($asbis, $productPath, $pricePath);
            SupplierProduct::query()->create([...$audit['candidate_payloads'][0], 'received_at' => now()]);
            $payload = $this->runVerification($asbis, $productPath, $pricePath, $audit, [
                '--expected-product-list-sha256' => str_repeat('0', 64),
                '--expected-candidate-sha256' => str_repeat('1', 64),
                '--expected-asbis-staged-count' => 99,
            ]);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertFalse($payload['verification_passed']);
        $this->assertSame('source_mismatch', $payload['verdict']);
        $this->assertArrayHasKey('source_fingerprint_mismatch', $payload['issue_counts']);
        $this->assertArrayHasKey('candidate_set_fingerprint_mismatch', $payload['issue_counts']);
        $this->assertArrayHasKey('expected_asbis_staged_count_mismatch', $payload['issue_counts']);
        $this->assertSame(0, $payload['records_changed']['products']);
    }

    /** @throws JsonException */
    public function test_unsafe_flags_and_schedule_fail_without_repair(): void
    {
        $this->safeConfig();
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => true]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $audit = $this->sourceAudit($asbis, $productPath, $pricePath);
            SupplierProduct::query()->create([...$audit['candidate_payloads'][0], 'received_at' => now()]);
            config(['services.asbis_dual_feed_staging_apply.enabled' => true]);
            $payload = $this->runVerification($asbis, $productPath, $pricePath, $audit);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertFalse($payload['verification_passed']);
        $this->assertSame('unsafe_configuration', $payload['verdict']);
        $this->assertArrayHasKey('apply_feature_flag_enabled', $payload['issue_counts']);
        $this->assertArrayHasKey('asbis_schedule_enabled', $payload['issue_counts']);
        $this->assertSame(0, $payload['records_changed']['supplier_products']);
    }

    /** @throws JsonException */
    public function test_duplicate_staged_sku_and_raw_provenance_mismatch_are_reported(): void
    {
        $this->safeConfig();
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis', 'schedule_enabled' => false]);
        [$productPath, $pricePath] = $this->writeFixtures();

        try {
            $audit = $this->sourceAudit($asbis, $productPath, $pricePath);
            $payload = $audit['candidate_payloads'][0];
            $payload['raw_data']['source'] = 'unexpected_source';
            SupplierProduct::query()->create([...$payload, 'received_at' => now()]);
            SupplierProduct::query()->create([...$payload, 'received_at' => now()]);
            $result = $this->runVerification($asbis, $productPath, $pricePath, $audit, [
                '--expected-asbis-staged-count' => 2,
                '--expected-total-staged-count' => 2,
            ]);
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }

        $this->assertFalse($result['verification_passed']);
        $this->assertSame('candidate_mismatch', $result['verdict']);
        $this->assertArrayHasKey('duplicate_or_blank_staged_skus', $result['issue_counts']);
        $this->assertArrayHasKey('raw_provenance_mismatch', $result['issue_counts']);
        $this->assertSame(0, $result['records_changed']['products']);
    }

    /** @throws JsonException */
    public function test_remote_sources_are_refused_without_path_or_secret_output(): void
    {
        Http::fake();
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);

        $exit = Artisan::call('suppliers:audit-asbis-post-apply-verification', [
            '--supplier' => $asbis->slug,
            '--product-list' => 'https://example.invalid/ProductList.xml?secret=DO-NOT-PRINT',
            '--price-avail-fixture' => 'tests/Fixtures/Suppliers/asbis_dual/PriceAvail.xml',
            '--format' => 'json',
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('remote_source_refused', array_key_first($payload['issue_counts']));
        $this->assertStringNotContainsString('DO-NOT-PRINT', $output);
        $this->assertStringNotContainsString('example.invalid', $output);
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function sourceAudit(Supplier $supplier, string $productPath, string $pricePath): array
    {
        return app(AsbisApplyReadinessAuditService::class)->run([
            'supplier' => $supplier->slug,
            'product_list_fixture' => $productPath,
            'price_avail_fixture' => $pricePath,
            'product_key' => 'ProductCode',
            'price_key' => 'WIC',
            'mode' => 'post_apply_verification',
            'full_file' => true,
            'include_candidate_payloads' => true,
            'ignore_existing_supplier_products' => true,
        ]);
    }

    /** @param array<string, mixed> $audit */
    private function runVerification(Supplier $supplier, string $productPath, string $pricePath, array $audit, array $extra = []): array
    {
        $arguments = array_merge([
            '--supplier' => $supplier->slug,
            '--product-list-fixture' => $productPath,
            '--price-avail-fixture' => $pricePath,
            '--product-key' => 'ProductCode',
            '--price-key' => 'WIC',
            '--expected-product-list-sha256' => $audit['source_fingerprints']['product_list_sha256'],
            '--expected-price-avail-sha256' => $audit['source_fingerprints']['price_avail_sha256'],
            '--expected-candidate-sha256' => $audit['ready_to_create_candidate_set_sha256'],
            '--expected-ready-count' => count($audit['candidate_payloads']),
            '--expected-asbis-staged-count' => count($audit['candidate_payloads']),
            '--expected-total-staged-count' => count($audit['candidate_payloads']),
            '--format' => 'json',
        ], $extra);
        $exit = Artisan::call('suppliers:audit-asbis-post-apply-verification', $arguments);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $payload['_exit'] = $exit;

        return $payload;
    }

    private function safeConfig(): void
    {
        config([
            'services.asbis_dual_feed_staging_apply.enabled' => false,
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function writeFixtures(): array
    {
        $productPath = tempnam(sys_get_temp_dir(), 'asbis-verify-product-');
        $pricePath = tempnam(sys_get_temp_dir(), 'asbis-verify-price-');
        file_put_contents($productPath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ProductCatalog>
    <Product>
        <ProductCode>VERIFY-001</ProductCode>
        <EAN>000000000001</EAN>
        <MPN>VERIFY-MPN-001</MPN>
        <Vendor>Verify Brand</Vendor>
        <ProductDescription>Verification product</ProductDescription>
        <ProductCategory>Verification</ProductCategory>
    </Product>
</ProductCatalog>
XML);
        file_put_contents($pricePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CONTENT>
    <PRICE>
        <WIC>VERIFY-001</WIC>
        <MY_PRICE>10.50</MY_PRICE>
        <CURRENCY_CODE>EUR</CURRENCY_CODE>
        <AVAIL>In Stock</AVAIL>
        <EAN>000000000001</EAN>
        <DESCRIPTION>Verification product</DESCRIPTION>
    </PRICE>
</CONTENT>
XML);

        return [$productPath, $pricePath];
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        return collect([
            'products',
            'supplier_products',
            'suppliers',
            'categories',
            'supplier_category_mappings',
            'canonical_product_families',
            'category_product_attributes',
            'product_attributes',
            'attribute_values',
            'product_attribute_values',
            'catalog_sync_batches',
            'catalog_sync_logs',
        ])->mapWithKeys(fn (string $table): array => [$table => \Schema::hasTable($table) ? \DB::table($table)->count() : 0])->all();
    }
}
