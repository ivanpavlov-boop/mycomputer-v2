<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\AsbisCandidateFingerprintService;
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
                '--confirm-apply' => 'ASBIS-DUAL-FEED-STAGING',
                '--confirm-create-only' => 'CREATE_ONLY',
                '--confirm-no-catalog-sync' => 'NO-CATALOG-SYNC',
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
                '--confirm-apply' => 'ASBIS-DUAL-FEED-STAGING',
                '--confirm-create-only' => 'CREATE_ONLY',
                '--confirm-no-catalog-sync' => 'NO-CATALOG-SYNC',
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
        $this->assertSame('ASBIS-CANDIDATE-001', $staged->supplier_sku);
        $this->assertSame('000000000001', $staged->ean);
        $this->assertSame('10.50', (string) $staged->price);
        $this->assertSame('in_stock', $staged->external_availability_status);
        $this->assertSame($dryRun['source_fingerprints']['product_list_sha256'], $staged->raw_data['product_list_sha256']);
        $this->assertSame($dryRun['source_fingerprints']['price_avail_sha256'], $staged->raw_data['price_avail_sha256']);
        $this->assertNull($staged->product_id);
        $this->assertSame('pending_review', $staged->status);
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
                '--confirm-apply' => 'ASBIS-DUAL-FEED-STAGING',
                '--confirm-create-only' => 'CREATE_ONLY',
                '--confirm-no-catalog-sync' => 'NO-CATALOG-SYNC',
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
