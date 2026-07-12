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
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Tests\TestCase;

class SupplierOnboardingReadinessMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_read_only_and_has_no_prohibited_options(): void
    {
        $command = Artisan::all()['suppliers:audit-onboarding-readiness-matrix'];
        $definition = $command->getDefinition();

        $this->assertStringContainsString('Read-only', (string) $command->getDescription());
        $this->assertStringContainsString('no feed requests', (string) $command->getDescription());
        $this->assertStringContainsString('no imports', (string) $command->getDescription());
        $this->assertStringContainsString('no writes', (string) $command->getDescription());
        $this->assertStringContainsString('Catalog Sync', (string) $command->getDescription());

        foreach ([
            'supplier', 'active-only', 'include-disabled', 'include-staging-counts', 'include-mapping-counts',
            'format', 'summary-only', 'sample-limit', 'issue-sample-limit', 'sort', 'direction',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }

        foreach ([
            'apply', 'fix', 'repair', 'create', 'update', 'delete', 'sync', 'sync-all', 'import', 'fetch',
            'download', 'enable', 'disable', 'confirm-supplier', 'schedule', 'queue', 'dispatch',
            'select-supplier', 'set-priority',
        ] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
    }

    /** @throws JsonException */
    public function test_matrix_reports_sanitized_counts_stages_sorting_and_zero_mutations(): void
    {
        Bus::fake();
        Http::fake();
        $supplier = $this->configuredSupplier('Generic XML Supplier');
        $disabled = Supplier::factory()->create([
            'company_name' => 'Disabled Supplier',
            'slug' => 'disabled-supplier',
            'status' => 'inactive',
            'import_enabled' => false,
            'schedule_enabled' => false,
        ]);
        $family = CanonicalProductFamily::query()->create([
            'code' => 'fixture-family',
            'name_bg' => 'Fixture family',
            'active' => true,
        ]);
        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_key' => $supplier->slug,
            'supplier_name' => $supplier->company_name,
            'supplier_category_name' => 'Computers',
            'canonical_product_family_id' => $family->id,
            'status' => SupplierCategoryMapping::STATUS_PENDING_REVIEW,
        ]);
        $this->stagedProduct($supplier, 'PRIVATE-SKU-ONE');
        $this->stagedProduct($supplier, 'PRIVATE-SKU-ONE');
        $this->stagedProduct($supplier, null);
        $counts = $this->protectedCounts();

        $payload = $this->commandJson([
            '--format' => 'json',
            '--include-staging-counts' => true,
            '--include-mapping-counts' => true,
            '--sample-limit' => 1,
            '--sort' => 'supplier',
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $generic = collect($payload['suppliers'])->firstWhere('supplier_key', $supplier->slug);

        $this->assertSame('supplier-readiness-matrix-v1', $payload['schema_version']);
        $this->assertSame('multi_supplier_readiness_matrix', $payload['mode']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame(2, $payload['supplier_count']);
        $this->assertSame(1, $payload['active_supplier_count']);
        $this->assertSame('source_profile_required', $generic['readiness_stage']);
        $this->assertTrue($generic['driver_available']);
        $this->assertFalse($generic['feed_profile_available']);
        $this->assertSame(3, $generic['staging_row_count']);
        $this->assertSame(3, $generic['unlinked_staging_row_count']);
        $this->assertSame(1, $generic['staging_diagnostics']['duplicate_supplier_sku_group_count']);
        $this->assertSame(1, $generic['staging_diagnostics']['blank_supplier_sku_count']);
        $this->assertCount(1, $generic['staging_diagnostics']['supplier_sku_sha256_samples']);
        $this->assertSame(1, $generic['mapping_diagnostics']['pending_mapping_count']);
        $this->assertSame(1, $generic['canonical_family_count']);
        $this->assertSame($this->zeroRecordsChanged(), $payload['records_changed']);
        $this->assertSame($counts, $this->protectedCounts());
        $this->assertStringNotContainsString('PRIVATE-SKU-ONE', $encoded);
        $this->assertStringNotContainsString('MATRIX_SECRET', $encoded);
        $this->assertStringNotContainsString('matrix-user', $encoded);
        $this->assertStringNotContainsString('feeds.example.test', $encoded);
        Bus::assertNotDispatched(RunSupplierImportJob::class);
        Bus::assertNotDispatched(ProcessXmlSupplierFeed::class);
        Bus::assertNotDispatched(ProcessSupplierImportRunJob::class);
        Http::assertNothingSent();

        $activeOnly = $this->commandJson(['--format' => 'json', '--active-only' => true]);
        $this->assertCount(1, $activeOnly['suppliers']);
        $this->assertSame($supplier->slug, $activeOnly['suppliers'][0]['supplier_key']);

        $withDisabled = $this->commandJson(['--format' => 'json', '--include-disabled' => true]);
        $this->assertCount(2, $withDisabled['suppliers']);

        $byStaging = $this->commandJson(['--format' => 'json', '--sort' => 'staging', '--direction' => 'desc']);
        $this->assertSame($supplier->slug, $byStaging['suppliers'][0]['supplier_key']);
        $this->assertSame($disabled->slug, collect($payload['suppliers'])->firstWhere('supplier_key', $disabled->slug)['supplier_key']);
    }

    /** @throws JsonException */
    public function test_verified_staging_evidence_is_based_on_provenance_not_supplier_slug(): void
    {
        $supplier = $this->configuredSupplier('Reference Supplier', 'reference-supplier');
        $this->stagedProduct($supplier, 'REFERENCE-001', [
            'raw_data' => [
                'source' => 'asbis_dual_feed',
                'candidate_payload_schema_version' => 'asbis-dual-feed-staging-candidate-v2',
                'product_list_sha256' => str_repeat('a', 64),
                'price_avail_sha256' => str_repeat('b', 64),
            ],
        ]);

        $payload = $this->commandJson(['--format' => 'json', '--supplier' => $supplier->slug]);
        $row = $payload['suppliers'][0];

        $this->assertSame('reference-supplier', $row['supplier_key']);
        $this->assertSame('staging_verified', $row['readiness_stage']);
        $this->assertTrue($row['feed_profile_available']);
        $this->assertTrue($row['preview_capability']);
        $this->assertTrue($row['controlled_staging_capability']);
        $this->assertTrue($row['post_apply_verification_capability']);
        $this->assertFalse($row['requires_production_read_only_audit']);
        $this->assertSame(1, $row['evidence']['verified_staging_evidence_count']);
    }

    /** @throws JsonException */
    public function test_linked_staging_schedule_risk_and_unsafe_flags_block_the_matrix_without_writes(): void
    {
        $supplier = $this->configuredSupplier('Blocked Supplier');
        $product = Product::factory()->create();
        $supplier->update(['schedule_enabled' => true, 'schedule_type' => 'daily']);
        $this->stagedProduct($supplier, 'LINKED-001', ['product_id' => $product->id]);

        $blocked = $this->commandJson(['--format' => 'json']);
        $row = $blocked['suppliers'][0];
        $blockers = collect($row['blockers'])->pluck('code')->all();

        $this->assertSame('blocked', $row['readiness_stage']);
        $this->assertContains('linked_staging_before_approval', $blockers);
        $this->assertContains('schedule_enabled_too_early', $blockers);

        config(['catalog_sync.update_enabled' => true]);
        $this->assertSame(1, Artisan::call('suppliers:audit-onboarding-readiness-matrix', ['--format' => 'json']));
        $unsafe = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('unsafe_configuration', $unsafe['matrix_verdict']);
        $this->assertTrue($unsafe['global_safety_flags']['catalog_sync_update_enabled']);
        $this->assertSame($this->zeroRecordsChanged(), $unsafe['records_changed']);
    }

    /** @throws JsonException */
    public function test_table_output_is_readable_and_summary_only_suppresses_supplier_rows(): void
    {
        $supplier = $this->configuredSupplier('Table Supplier');

        $this->assertSame(0, Artisan::call('suppliers:audit-onboarding-readiness-matrix', [
            '--summary-only' => true,
            '--supplier' => $supplier->slug,
        ]));
        $output = Artisan::output();

        $this->assertStringContainsString('Multi-supplier onboarding readiness matrix', $output);
        $this->assertStringContainsString('Read-only. No feed requests, imports, writes, Catalog Sync', $output);
        $this->assertStringContainsString('Matrix verdict:', $output);
        $this->assertStringNotContainsString('Table Supplier', $output);
    }

    /** @return array<string, mixed> */
    private function commandJson(array $arguments): array
    {
        $this->assertSame(0, Artisan::call('suppliers:audit-onboarding-readiness-matrix', $arguments));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function configuredSupplier(string $name, ?string $slug = null): Supplier
    {
        $supplier = Supplier::factory()->create([
            'company_name' => $name,
            'slug' => $slug ?? str($name)->slug()->value(),
            'status' => 'active',
            'import_enabled' => true,
            'schedule_enabled' => false,
            'schedule_type' => 'manual_only',
        ]);
        SupplierFeed::factory()->create([
            'supplier_id' => $supplier->id,
            'feed_type' => 'xml',
            'feed_url' => 'https://feeds.example.test/path?token=MATRIX_SECRET',
            'username' => 'matrix-user',
            'password' => 'MATRIX_PASSWORD',
            'mapping' => ['supplier_sku' => 'product.sku'],
            'status' => 'active',
        ]);
        XmlMappingTemplate::factory()->create(['supplier_id' => $supplier->id]);

        return $supplier;
    }

    /** @param array<string, mixed> $overrides */
    private function stagedProduct(Supplier $supplier, ?string $supplierSku, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => $supplierSku,
            'ean' => null,
            'mpn' => null,
            'name' => 'Staged supplier product',
            'brand_name' => null,
            'category_name' => null,
            'price' => null,
            'quantity' => null,
            'currency' => 'EUR',
            'raw_data' => ['source' => 'fixture'],
            'payload_hash' => sha1((string) str()->uuid()),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        return collect([
            'suppliers' => Supplier::class,
            'supplier_products' => SupplierProduct::class,
            'products' => Product::class,
            'categories' => Category::class,
            'supplier_category_mappings' => SupplierCategoryMapping::class,
            'canonical_product_families' => CanonicalProductFamily::class,
            'category_product_attributes' => CategoryProductAttribute::class,
            'product_attributes' => ProductAttribute::class,
            'attribute_values' => AttributeValue::class,
            'product_attribute_values' => ProductAttributeValue::class,
        ])->mapWithKeys(fn (string $model, string $table): array => [
            $table => Schema::hasTable($table) ? $model::query()->count() : 0,
        ])->all();
    }

    /** @return array<string, int> */
    private function zeroRecordsChanged(): array
    {
        $recordsChanged = [
            'suppliers' => 0,
            'supplier_products' => 0,
            'products' => 0,
            'categories' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
            'catalog_sync_batches' => 0,
            'catalog_sync_logs' => 0,
            'catalog_sync' => 0,
        ];

        ksort($recordsChanged);

        return $recordsChanged;
    }
}
