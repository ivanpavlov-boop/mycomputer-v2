<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DesignPreviewFeedProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_design_command_reports_aggregate_preview_only_classifications_without_mutation(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->observedStagedRows($supplier);
        $before = $this->protectedCounts();
        Bus::fake();
        Http::fake();

        $payload = $this->commandJson($supplier);

        $this->assertTrue($payload['success']);
        $this->assertSame('preview_feed_profile_requires_human_decisions', $payload['verdict']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['human_review_required']);
        $this->assertContains('APCOM-STOCK-001', $payload['blocking_decision_ids']);
        $this->assertContains('APCOM-PROHIBIT-UPDATE-SYNC-001', $payload['blocking_decision_ids']);
        $this->assertSame(2, $payload['aggregate_preview_counts']['would_create']);
        $this->assertSame(2, $payload['aggregate_preview_counts']['would_update']);
        $this->assertSame(1, $payload['aggregate_preview_counts']['would_delete']);
        $this->assertSame(1, $this->classificationCount($payload, 'eol_review'));
        $this->assertSame(1, $this->classificationCount($payload, 'zero_price_review'));
        $this->assertSame(1, $this->classificationCount($payload, 'blank_ean_review'));
        $this->assertSame(1, $this->classificationCount($payload, 'ean_conflict_review'));
        $this->assertSame(4, $this->classificationCount($payload, 'unresolved_stock_review'));

        $this->assertFalse($payload['persisted_profile_created']);
        $this->assertFalse($payload['executable_import_configuration_created']);
        $this->assertFalse($payload['import_executed']);
        $this->assertFalse($payload['catalog_sync_executed']);
        $this->assertFalse($payload['links_changed']);
        $this->assertFalse($payload['schedule_changed']);
        $this->assertFalse($payload['images_imported']);
        $this->assertFalse($payload['automatic_execution_allowed']);
        $this->assertFalse($payload['catalog_write_allowed']);
        $this->assertFalse($payload['staging_write_allowed']);
        $this->assertFalse($payload['profile_persistence_allowed']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('APCOM-OBS-001', $encoded);
        $this->assertStringNotContainsString('2000000000001', $encoded);
        $this->assertStringNotContainsString('Synthetic observed product one', $encoded);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_design_command_rejects_unknown_register_without_reading_or_mutating(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->observedStagedRows($supplier);
        $before = $this->protectedCounts();
        Bus::fake();
        Http::fake();

        $this->assertSame(1, Artisan::call('suppliers:design-preview-feed-profile', array_merge($this->arguments($supplier), [
            '--decision-register' => 'unknown-register-v1',
            '--output' => 'json',
        ])));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['success']);
        $this->assertSame('preview_feed_profile_design_blocked', $payload['verdict']);
        $this->assertContains('unknown_decision_register', $payload['blockers']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_command_definition_has_no_apply_or_persist_controls(): void
    {
        $command = Artisan::all()['suppliers:design-preview-feed-profile'];

        $this->assertFalse($command->getDefinition()->hasOption('apply'));
        $this->assertFalse($command->getDefinition()->hasOption('persist'));
        $this->assertFalse($command->getDefinition()->hasOption('import'));
        $this->assertFalse($command->getDefinition()->hasOption('sync'));
        $this->assertFalse($command->getDefinition()->hasOption('schedule'));
    }

    /** @param array<string, mixed> $payload */
    private function classificationCount(array $payload, string $classification): int
    {
        return (int) (collect($payload['candidate_classifications'])
            ->firstWhere('classification', $classification)['count'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function commandJson(Supplier $supplier): array
    {
        $this->assertSame(0, Artisan::call('suppliers:design-preview-feed-profile', array_merge($this->arguments($supplier), ['--output' => 'json'])));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function arguments(Supplier $supplier): array
    {
        $source = base_path('tests/Fixtures/Suppliers/apcom_official_semantics/synthetic-apcom-observed-stock.xml');
        $stagedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->count();
        $linkedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->whereNotNull('product_id')->count();

        return [
            '--supplier' => 'apcom',
            '--source' => $source,
            '--source-format' => 'xml',
            '--semantics-profile' => 'apcom-observed-stock-v1',
            '--decision-register' => 'apcom-human-decisions-v1',
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
                'payload_hash' => hash('sha256', 'synthetic-preview-profile-'.$supplier->id.'-'.$index),
                'received_at' => now(),
                'status' => 'new',
            ]);
        }
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        $counts = [];
        foreach ([
            'suppliers', 'supplier_products', 'products', 'categories', 'supplier_category_mappings',
            'canonical_product_families', 'category_product_attributes', 'product_attributes', 'attribute_values',
            'product_attribute_values', 'catalog_sync_batches', 'catalog_sync_logs', 'supplier_import_runs', 'import_jobs',
        ] as $table) {
            $counts[$table] = Schema::hasTable($table) ? (int) \DB::table($table)->count() : 0;
        }
        $counts['catalog_sync'] = 0;
        ksort($counts);

        return $counts;
    }
}
