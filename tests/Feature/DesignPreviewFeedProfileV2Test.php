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

final class DesignPreviewFeedProfileV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_v2_command_emits_synthetic_mapping_and_blocked_gate_without_mutation(): void
    {
        $supplier = $this->supplierWithBaseline();
        $this->stagedRows($supplier);
        $before = $this->protectedCounts();
        Bus::fake();
        Http::fake();

        $this->assertSame(0, Artisan::call('suppliers:design-preview-feed-profile', $this->arguments($supplier)));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('apcom-human-decisions-v2', $payload['decision_register']['key']);
        $this->assertSame('apcom-preview-feed-profile-v2', $payload['preview_feed_profile']['key']);
        $this->assertArrayHasKey('canonical_status_model', $payload);
        $this->assertArrayHasKey('supplier_availability_policy', $payload);
        $this->assertArrayHasKey('public_quantity_policy', $payload);
        $this->assertArrayHasKey('availability_mapping_preview', $payload);
        $this->assertArrayHasKey('lifecycle_mapping_preview', $payload);
        $this->assertArrayHasKey('price_mapping_preview', $payload);
        $this->assertArrayHasKey('green_tax_policy', $payload);
        $this->assertArrayHasKey('profile_approval_gate', $payload);
        $this->assertSame('blocked_pending_human_decisions', $payload['profile_approval_gate']['gate_status']);
        $this->assertFalse($payload['profile_approval_gate']['operational_import_approval']);
        $this->assertFalse($payload['public_quantity_policy']['public_exact_quantity_allowed']);
        $this->assertSame('EUR', $payload['price_mapping_preview']['currency']);
        $this->assertSame('exclusive', $payload['price_mapping_preview']['vat_treatment']);
        $this->assertTrue($payload['green_tax_policy']['included_in_fd_price']);
        $this->assertTrue($payload['green_tax_policy']['contradiction_requires_review']);

        $examples = collect($payload['availability_mapping_preview'])->keyBy(fn (array $row): string => $row['raw_quantity_observed'].'|'.$row['canonical_lifecycle_status']);
        $this->assertSame('on_request', $examples['0|active']['canonical_public_status']);
        $this->assertSame('limited', $examples['5|active']['canonical_public_status']);
        $this->assertSame('in_stock', $examples['6|active']['canonical_public_status']);
        $this->assertTrue($examples['100|active']['quantity_is_capped']);
        $this->assertSame(100, $examples['100|active']['quantity_minimum']);
        $this->assertSame('last_units', $examples['3|eol']['canonical_public_status']);
        $this->assertSame('discontinued', $examples['0|eol']['canonical_public_status']);

        foreach ($payload['availability_mapping_preview'] as $example) {
            $this->assertFalse($example['exact_public_quantity_allowed']);
            $this->assertFalse($example['automatic_execution_allowed']);
            $this->assertFalse($example['catalog_write_allowed']);
            $this->assertFalse($example['staging_write_allowed']);
        }

        $this->assertFalse($payload['persisted_profile_created']);
        $this->assertFalse($payload['executable_import_configuration_created']);
        $this->assertFalse($payload['import_executed']);
        $this->assertFalse($payload['catalog_sync_executed']);
        $this->assertFalse($payload['links_changed']);
        $this->assertFalse($payload['schedule_changed']);
        $this->assertFalse($payload['images_imported']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $this->assertSame($before, $this->protectedCounts());
        $this->assertStringNotContainsString('APCOM-V2-', json_encode($payload, JSON_THROW_ON_ERROR));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_command_keeps_v1_default_and_exposes_no_approval_or_write_controls(): void
    {
        $command = Artisan::all()['suppliers:design-preview-feed-profile'];

        foreach (['apply', 'persist', 'approve', 'activate', 'import', 'sync', 'create', 'update', 'delete', 'link', 'unlink', 'enable', 'schedule', 'images'] as $option) {
            $this->assertFalse($command->getDefinition()->hasOption($option));
        }
        $this->assertTrue($command->getDefinition()->hasOption('preview-profile'));
    }

    /** @return array<string, mixed> */
    private function arguments(Supplier $supplier): array
    {
        $source = base_path('tests/Fixtures/Suppliers/apcom_authoritative_decisions_v2/synthetic-apcom-authoritative-v2.xml');
        $stagedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->count();
        $linkedCount = SupplierProduct::query()->where('supplier_id', $supplier->id)->whereNotNull('product_id')->count();

        return [
            '--supplier' => 'apcom',
            '--source' => $source,
            '--source-format' => 'xml',
            '--semantics-profile' => 'apcom-approved-business-semantics-v2',
            '--decision-register' => 'apcom-human-decisions-v2',
            '--preview-profile' => 'apcom-preview-feed-profile-v2',
            '--expected-sha256' => hash_file('sha256', $source),
            '--full-file' => true,
            '--expected-supplier-id' => (string) $supplier->id,
            '--expected-schedule-enabled' => 'false',
            '--expected-import-enabled' => 'true',
            '--expected-schedule-type' => 'twice_daily',
            '--expected-staged-count' => (string) $stagedCount,
            '--expected-linked-count' => (string) $linkedCount,
            '--expected-unlinked-count' => (string) ($stagedCount - $linkedCount),
            '--expected-last-import-at' => '2026-07-13 04:00:56',
            '--output' => 'json',
        ];
    }

    private function supplierWithBaseline(): Supplier
    {
        return Supplier::factory()->create([
            'company_name' => 'Synthetic APCOM V2',
            'slug' => 'apcom',
            'status' => 'active',
            'import_enabled' => true,
            'schedule_enabled' => false,
            'schedule_type' => 'twice_daily',
            'last_import_at' => '2026-07-13 04:00:56',
        ]);
    }

    private function stagedRows(Supplier $supplier): void
    {
        $product = Product::factory()->create();
        foreach (['APCOM-V2-000', 'APCOM-V2-001', 'APCOM-V2-005', 'APCOM-V2-006', 'APCOM-V2-040', 'APCOM-V2-100', 'APCOM-V2-EOL-003', 'APCOM-V2-EOL-100'] as $index => $sku) {
            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'product_id' => $index === 0 ? $product->id : null,
                'supplier_sku' => $sku,
                'ean' => $index === 0 ? null : '29999999990'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'name' => 'Synthetic staging row '.($index + 1),
                'brand_name' => 'SyntheticBrand',
                'category_name' => 'Synthetic Category',
                'price' => '100.00',
                'quantity' => 1,
                'currency' => 'EUR',
                'raw_data' => ['synthetic' => true],
                'payload_hash' => hash('sha256', 'synthetic-v2-'.$supplier->id.'-'.$index),
                'received_at' => now(),
                'status' => 'new',
            ]);
        }
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'supplier_sku' => 'APCOM-V2-STAGING-ONLY',
            'ean' => '2999999999099',
            'name' => 'Synthetic linked staging-only row',
            'brand_name' => 'SyntheticBrand',
            'category_name' => 'Synthetic Category',
            'price' => '100.00',
            'quantity' => 1,
            'currency' => 'EUR',
            'raw_data' => ['synthetic' => true],
            'payload_hash' => hash('sha256', 'synthetic-v2-staging-only-'.$supplier->id),
            'received_at' => now(),
            'status' => 'new',
        ]);
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
