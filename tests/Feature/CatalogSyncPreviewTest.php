<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierExclusionRule;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Pricing\PricingEngine;
use App\Services\Products\CatalogSyncPreviewService;
use App\Services\Suppliers\SupplierExclusionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CatalogSyncPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_preview_does_not_write_catalog_product(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-CREATE-001',
            'ean' => '1111111111111',
            'mpn' => 'APC-MPN-CREATE',
            'name' => 'APCOM Create Preview Product',
            'price' => 100,
            'quantity' => 7,
        ]);

        $beforeCount = Product::query()->count();
        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);

        $this->assertSame('create', $row['target_catalog_action']);
        $this->assertSame([], $row['matched_by']);
        $this->assertSame('None', $row['matched_by_display']);
        $this->assertSame('New catalog product', $row['reason']);
        $this->assertSame('New catalog product will be created', $row['result']);
        $this->assertSame($beforeCount, Product::query()->count());
    }

    public function test_update_preview_shows_match_reason(): void
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'sku' => 'CAT-SKU-001',
            'ean' => '2222222222222',
            'mpn' => 'CAT-MPN-001',
            'name' => 'Existing Catalog Product',
            'price' => 120,
            'quantity' => 3,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'SUP-SKU-001',
            'ean' => '2222222222222',
            'mpn' => 'SUP-MPN-001',
            'quantity' => 8,
        ]);

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);

        $this->assertSame('update', $row['target_catalog_action']);
        $this->assertSame(['ean'], $row['matched_by']);
        $this->assertSame('EAN', $row['matched_by_display']);
        $this->assertSame($product->id, $row['target_product_id']);
        $this->assertSame('Existing Catalog Product', $row['target_product_name']);
        $this->assertSame(120.0, (float) $row['current_price']);
        $this->assertSame(3, $row['current_stock']);
        $this->assertSame(8, $row['new_stock']);
        $this->assertSame('Existing catalog product will be updated', $row['result']);
    }

    public function test_conflict_preview_detects_multiple_catalog_matches(): void
    {
        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'sku' => 'CAT-CONFLICT-001',
            'ean' => '3333333333333',
            'mpn' => 'CAT-MPN-A',
        ]);
        Product::factory()->create([
            'sku' => 'CAT-CONFLICT-002',
            'ean' => '3333333333333',
            'mpn' => 'SUP-MPN-CONFLICT',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'SUP-CONFLICT-001',
            'ean' => '3333333333333',
            'mpn' => 'SUP-MPN-CONFLICT',
        ]);

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);

        $this->assertSame('conflict', $row['target_catalog_action']);
        $this->assertContains('multiple_catalog_matches', $row['conflict_reasons']);
        $this->assertSame(['ean'], $row['matched_by']);
        $this->assertSame('Conflict detected', $row['result']);
    }

    public function test_pricing_preview_shows_inheritance_margin_and_final_price(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $category = Category::factory()->create(['name' => 'Video Cards', 'slug' => 'video-cards']);
        $brand = Brand::factory()->create(['name' => 'ASUS', 'slug' => 'asus']);
        $supplierProduct = $this->supplierProduct($supplier, [
            'brand_name' => 'ASUS',
            'category_name' => 'Video Cards',
            'price' => 100,
        ]);

        PricingRule::query()->create([
            'name' => 'Global default margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);
        PricingRule::query()->create([
            'name' => 'APCOM supplier margin',
            'scope_type' => PricingRule::SCOPE_SUPPLIER,
            'supplier_id' => $supplier->id,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 15,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);
        PricingRule::query()->create([
            'name' => 'Video Cards margin',
            'scope_type' => PricingRule::SCOPE_CATEGORY,
            'category_id' => $category->id,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 12,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);
        PricingRule::query()->create([
            'name' => 'Video Cards ASUS margin',
            'scope_type' => PricingRule::SCOPE_CATEGORY_BRAND,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 16,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);

        $this->assertSame('Category + Brand', $row['pricing_rule_applied']);
        $this->assertSame('Video Cards + ASUS', $row['matched_pricing_rule']);
        $this->assertSame('Video Cards + ASUS', $row['winning_pricing_rule']);
        $this->assertSame([
            'Global Default',
            'Supplier APCOM',
            'Video Cards',
            'Video Cards + ASUS',
        ], $row['pricing_inheritance']);
        $this->assertSame('First active matching rule by priority: Category + Brand', $row['pricing_rule_reason']);
        $this->assertSame('16%', $row['margin_rule']);
        $this->assertSame(16.0, $row['margin_amount']);
        $this->assertSame(16.0, $row['margin_applied']);
        $this->assertSame(16.0, $row['profit_amount']);
        $this->assertSame(16.0, $row['margin_percent']);
        $this->assertSame(116.0, $row['final_calculated_selling_price']);
        $this->assertSame('Video Cards', $row['normalized_category']);
    }

    public function test_summary_includes_margin_revenue_and_profit_metrics(): void
    {
        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'PROFIT-001',
            'price' => 100,
            'quantity' => 2,
        ]);

        PricingRule::query()->create([
            'name' => 'Global default margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        $preview = app(CatalogSyncPreviewService::class)->preview([], 50);

        $this->assertSame(20.0, $preview['summary']['average_margin']);
        $this->assertSame(240.0, $preview['summary']['estimated_revenue']);
        $this->assertSame(40.0, $preview['summary']['estimated_profit']);
    }

    public function test_quick_filters_filter_rows_without_writing_catalog_data(): void
    {
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM']);
        $other = Supplier::factory()->create(['company_name' => 'Other']);

        $this->supplierProduct($apcom, [
            'supplier_sku' => 'APC-MISSING-EAN',
            'ean' => null,
            'quantity' => 0,
            'raw_data' => [],
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-WITH-EAN',
            'ean' => '9999999999999',
            'quantity' => 5,
        ]);

        $service = app(CatalogSyncPreviewService::class);

        $this->assertCount(1, $service->preview(['quick_filter' => 'apcom'], 50)['rows']);
        $this->assertCount(1, $service->preview(['quick_filter' => 'missing_ean'], 50)['rows']);
        $this->assertCount(1, $service->preview(['quick_filter' => 'zero_stock'], 50)['rows']);
        $this->assertCount(1, $service->preview(['quick_filter' => 'missing_images'], 50)['rows']);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_preview_filters_are_applied_before_limit(): void
    {
        $supplier = Supplier::factory()->create();

        for ($index = 1; $index <= 50; $index++) {
            $this->supplierProduct($supplier, [
                'supplier_sku' => 'LIMIT-CREATE-'.$index,
                'ean' => '1000000000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'quantity' => 5,
            ]);
        }

        $this->supplierProduct($supplier, [
            'supplier_sku' => 'LIMIT-MISSING-EAN',
            'ean' => null,
            'quantity' => 0,
        ]);
        Product::factory()->create([
            'ean' => '9990000000001',
            'price' => 200,
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'LIMIT-UPDATE',
            'ean' => '9990000000001',
            'quantity' => 7,
        ]);

        $service = app(CatalogSyncPreviewService::class);

        $missingEanRows = $service->preview(['quick_filter' => 'missing_ean'], 50)['rows'];
        $updateRows = $service->preview(['action' => 'update'], 50)['rows'];

        $this->assertCount(1, $missingEanRows);
        $this->assertSame('LIMIT-MISSING-EAN', $missingEanRows[0]['supplier_sku']);
        $this->assertCount(1, $updateRows);
        $this->assertSame('LIMIT-UPDATE', $updateRows[0]['supplier_sku']);
    }

    public function test_preview_rows_can_be_sorted_by_business_columns(): void
    {
        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'SORT-B',
            'name' => 'B Product',
            'price' => 200,
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'SORT-A',
            'name' => 'A Product',
            'price' => 100,
        ]);

        $preview = app(CatalogSyncPreviewService::class)->preview([
            'sort_column' => 'supplier_price',
            'sort_direction' => 'desc',
        ], 50);

        $this->assertSame('B Product', $preview['rows'][0]['product_name']);
        $this->assertSame('A Product', $preview['rows'][1]['product_name']);
    }

    public function test_category_and_supplier_filters_are_applied(): void
    {
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM']);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier']);
        $this->supplierProduct($apcom, [
            'supplier_sku' => 'APC-GPU-001',
            'brand_name' => 'ASUS',
            'category_name' => 'Video Cards',
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-CPU-001',
            'brand_name' => 'AMD',
            'category_name' => 'Processors',
        ]);

        $preview = app(CatalogSyncPreviewService::class)->preview([
            'supplier_id' => $apcom->id,
            'category' => 'Video',
            'brand' => 'ASUS',
        ], 50);

        $this->assertCount(1, $preview['rows']);
        $this->assertSame('APCOM', $preview['rows'][0]['supplier_name']);
        $this->assertSame(1, $preview['summary']['to_create']);
    }

    public function test_preview_is_read_only_for_existing_catalog_product(): void
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'sku' => 'READONLY-001',
            'ean' => '5555555555555',
            'price' => 999,
            'quantity' => 1,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'SUP-READONLY-001',
            'ean' => '5555555555555',
            'price' => 100,
            'quantity' => 99,
        ]);

        app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);

        $product->refresh();

        $this->assertSame('999.00', $product->price);
        $this->assertSame(1, $product->quantity);
        $this->assertNull($supplierProduct->refresh()->product_id);
        $this->assertSame('new', $supplierProduct->status);
    }

    public function test_catalog_sync_preview_page_renders_legacy_scalar_raw_payloads(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Legacy Raw Payload Product',
            'raw_data' => 'legacy payload',
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Legacy Raw Payload Product');
    }

    public function test_catalog_sync_preview_page_renders_legacy_scalar_raw_payloads_with_eol_exclusion_rule(): void
    {
        $this->actingAsSupplierManager();

        SupplierExclusionRule::query()->create([
            'name' => 'Exclude EOL products',
            'is_active' => true,
            'exclude_eol' => true,
            'priority' => 1,
        ]);

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Legacy EOL Check Product',
            'raw_data' => 'legacy payload',
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Legacy EOL Check Product');
    }

    public function test_catalog_sync_preview_page_renders_failed_rows_as_conflicts(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Broken Pricing Preview Product',
        ]);

        $this->mock(PricingEngine::class, function ($mock): void {
            $mock
                ->shouldReceive('calculateForSupplierProduct')
                ->andThrow(new RuntimeException('Pricing preview failed'));
        });

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Broken Pricing Preview Product')
            ->assertSee('Preview generation failed')
            ->assertSee('preview_generation_failed');
    }

    public function test_catalog_sync_preview_page_renders_diagnostic_state_when_preview_generation_fails(): void
    {
        $this->actingAsSupplierManager();

        Log::spy();

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock
                ->shouldReceive('preview')
                ->once()
                ->andThrow(new RuntimeException('Top-level preview failure'));
        });

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Catalog Sync Preview could not be generated.')
            ->assertSee('Top-level preview failure');

        Log::shouldHaveReceived('error')
            ->with('Catalog Sync Preview page failed to render preview.', Mockery::on(fn (array $context): bool => $context['exception'] === RuntimeException::class
                && $context['message'] === 'Top-level preview failure'
                && isset($context['filters'])))
            ->once();
    }

    public function test_catalog_sync_preview_diagnostics_mode_renders_static_page_without_preview_service(): void
    {
        $this->actingAsSupplierManager();

        config(['services.catalog_sync_preview.diagnostics' => true]);

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock->shouldNotReceive('preview');
        });

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Catalog Sync Preview diagnostics OK')
            ->assertSee('Static Filament page render completed without loading filters, suppliers, or preview services.')
            ->assertDontSee('Quick filters');
    }

    public function test_catalog_sync_preview_supplier_diagnostic_step_renders_supplier_report(): void
    {
        $this->actingAsSupplierManager();

        Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
            'status' => 'active',
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=suppliers')
            ->assertOk()
            ->assertSee('Catalog Sync Preview diagnostics OK')
            ->assertSee('Step: suppliers')
            ->assertSee('Supplier lookup completed.')
            ->assertSee('APCOM');
    }

    public function test_catalog_sync_preview_filter_diagnostic_step_renders_form_without_preview_generation(): void
    {
        $this->actingAsSupplierManager();

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock->shouldNotReceive('preview');
            $mock->shouldNotReceive('previewSupplierProduct');
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=filters')
            ->assertOk()
            ->assertSee('Step: filters')
            ->assertSee('Filter form diagnostic selected.')
            ->assertSee('Supplier');
    }

    public function test_catalog_sync_preview_query_rows_diagnostic_step_does_not_preview_rows(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-DIAGNOSTIC-ROW',
        ]);

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock->shouldNotReceive('preview');
            $mock->shouldNotReceive('previewSupplierProduct');
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=query_rows')
            ->assertOk()
            ->assertSee('Step: query_rows')
            ->assertSee('Supplier product row query completed without preview generation.')
            ->assertSee('APC-DIAGNOSTIC-ROW');
    }

    public function test_catalog_sync_preview_query_first_five_diagnostic_step_does_not_preview_rows(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);

        for ($index = 1; $index <= 6; $index++) {
            $this->supplierProduct($supplier, [
                'supplier_sku' => 'APC-FIRST-'.$index,
                'name' => 'APCOM First Query Product '.$index,
            ]);
        }

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock->shouldNotReceive('preview');
            $mock->shouldNotReceive('previewSupplierProduct');
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=query_first_5')
            ->assertOk()
            ->assertSee('Step: query_first_5')
            ->assertSee('First 5 supplier products queried without preview generation.')
            ->assertSee('APC-FIRST-5')
            ->assertDontSee('APC-FIRST-6');
    }

    public function test_catalog_sync_preview_selected_supplier_diagnostic_step_renders_default_supplier(): void
    {
        $this->actingAsSupplierManager();

        Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=selected_supplier')
            ->assertOk()
            ->assertSee('Step: selected_supplier')
            ->assertSee('Selected supplier lookup completed.')
            ->assertSee('APCOM');
    }

    public function test_catalog_sync_preview_limited_preview_diagnostic_step_renders_preview_summary(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'APCOM Diagnostic Preview 50 Product',
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=preview_50')
            ->assertOk()
            ->assertSee('Step: preview_50')
            ->assertSee('Limited 50-row preview completed.')
            ->assertSee('rows_rendered');
    }

    public function test_catalog_sync_preview_first_id_diagnostic_previews_only_first_row(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $firstProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-FIRST-ID',
            'name' => 'APCOM First ID Product',
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-SECOND-ID',
            'name' => 'APCOM Second ID Product',
        ]);

        Log::spy();

        $this->mock(CatalogSyncPreviewService::class, function ($mock) use ($firstProduct): void {
            $mock
                ->shouldReceive('previewSupplierProduct')
                ->once()
                ->with(Mockery::on(fn (SupplierProduct $supplierProduct): bool => $supplierProduct->is($firstProduct)))
                ->andReturn([
                    'product_name' => 'APCOM First ID Product',
                    'target_catalog_action' => 'create',
                    'reason' => 'New catalog product',
                    'result' => 'New catalog product will be created',
                    'conflict_reasons' => [],
                ]);
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=preview_first_id')
            ->assertOk()
            ->assertSee('Step: preview_first_id')
            ->assertSee('Single supplier product preview completed.')
            ->assertSee('APC-FIRST-ID')
            ->assertDontSee('APC-SECOND-ID');

        Log::shouldHaveReceived('info')
            ->with('Catalog Sync Preview diagnostic row preview starting.', Mockery::on(fn (array $context): bool => $context['diagnostic_step'] === 'preview_first_id'
                && $context['supplier_product_id'] === $firstProduct->id))
            ->once();
        Log::shouldHaveReceived('info')
            ->with('Catalog Sync Preview diagnostic row preview completed.', Mockery::on(fn (array $context): bool => $context['diagnostic_step'] === 'preview_first_id'
                && $context['supplier_product_id'] === $firstProduct->id))
            ->once();
    }

    public function test_catalog_sync_preview_explicit_row_diagnostic_previews_requested_row_only(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-IGNORED-ROW',
        ]);
        $requestedProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-REQUESTED-ROW',
            'name' => 'APCOM Requested Row Product',
        ]);

        $this->mock(CatalogSyncPreviewService::class, function ($mock) use ($requestedProduct): void {
            $mock
                ->shouldReceive('previewSupplierProduct')
                ->once()
                ->with(Mockery::on(fn (SupplierProduct $supplierProduct): bool => $supplierProduct->is($requestedProduct)))
                ->andReturn([
                    'product_name' => 'APCOM Requested Row Product',
                    'target_catalog_action' => 'update',
                    'reason' => 'Existing catalog product matched',
                    'result' => 'Existing catalog product will be updated',
                    'conflict_reasons' => [],
                ]);
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=preview_row&supplier_product_id='.$requestedProduct->id)
            ->assertOk()
            ->assertSee('Step: preview_row')
            ->assertSee('Single supplier product preview completed.')
            ->assertSee('APC-REQUESTED-ROW')
            ->assertDontSee('APC-IGNORED-ROW');
    }

    public function test_catalog_sync_preview_explicit_row_diagnostic_failure_renders_compact_conflict(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-REQUESTED-FAIL',
            'name' => 'APCOM Requested Failed Product',
        ]);

        Log::spy();

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock
                ->shouldReceive('previewSupplierProduct')
                ->once()
                ->andThrow(new RuntimeException('Explicit row diagnostic failure'));
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=preview_row&supplier_product_id='.$supplierProduct->id)
            ->assertOk()
            ->assertSee('Step: preview_row')
            ->assertSee('Single supplier product preview failed.')
            ->assertSee('APC-REQUESTED-FAIL')
            ->assertSee('preview_generation_failed')
            ->assertSee('Explicit row diagnostic failure');

        Log::shouldHaveReceived('warning')
            ->with('Catalog Sync Preview diagnostic row preview failed.', Mockery::on(fn (array $context): bool => $context['diagnostic_step'] === 'preview_row'
                && $context['supplier_product_id'] === $supplierProduct->id
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'Explicit row diagnostic failure'))
            ->once();
    }

    public function test_catalog_sync_preview_trace_diagnostic_renders_compact_step_report(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-TRACE-ROW',
            'ean' => '879961009533',
            'name' => 'Satechi Duo Wireless Charger Power Bank Stand',
            'price' => 100,
            'quantity' => 7,
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=preview_trace&supplier_product_id='.$supplierProduct->id)
            ->assertOk()
            ->assertSee('Step: preview_trace')
            ->assertSee('Single supplier product preview trace completed.')
            ->assertSee('load_row')
            ->assertSee('apply_exclusion_checks')
            ->assertSee('find_matching_product')
            ->assertSee('duplicate_detection')
            ->assertSee('pricing_calculation')
            ->assertSee('offer_selection')
            ->assertSee('image_extraction')
            ->assertSee('build_preview_payload')
            ->assertSee('APC-TRACE-ROW')
            ->assertDontSee('raw_data');
    }

    public function test_catalog_sync_preview_trace_diagnostic_reports_failing_substep(): void
    {
        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-TRACE-FAIL',
            'ean' => '879961009533',
            'name' => 'Satechi Duo Wireless Charger Power Bank Stand',
            'price' => 100,
            'quantity' => 7,
        ]);

        Log::spy();

        $pricingEngine = Mockery::mock(PricingEngine::class);
        $pricingEngine
            ->shouldReceive('calculateForSupplierProduct')
            ->once()
            ->andThrow(new RuntimeException('Trace pricing failure'));

        $service = new CatalogSyncPreviewService(
            $pricingEngine,
            app(AvailabilityStatusMapper::class),
            app(SupplierExclusionService::class),
        );

        $trace = $service->traceSupplierProductPreview($supplierProduct->id);

        $this->assertSame('Single supplier product preview trace failed.', $trace['message']);
        $this->assertSame('duplicate_detection', $trace['last_successful_step']);
        $this->assertSame('pricing_calculation', $trace['failing_step']);
        $this->assertSame(RuntimeException::class, $trace['exception']['class']);
        $this->assertSame('Trace pricing failure', $trace['exception']['message']);

        Log::shouldHaveReceived('error')
            ->with('Catalog Sync Preview trace step failed.', Mockery::on(fn (array $context): bool => $context['step'] === 'pricing_calculation'
                && $context['supplier_product_id'] === $supplierProduct->id
                && $context['supplier_sku'] === 'APC-TRACE-FAIL'
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'Trace pricing failure'
                && isset($context['line'])))
            ->once();
    }

    public function test_catalog_sync_preview_smaller_preview_diagnostic_steps_are_available(): void
    {
        $this->actingAsSupplierManager();

        Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);

        foreach ([5, 10, 25] as $limit) {
            $this
                ->get(CatalogSyncPreview::getUrl()."?diagnostic_step=preview_{$limit}")
                ->assertOk()
                ->assertSee("Step: preview_{$limit}")
                ->assertSee("Limited {$limit}-row preview completed.");
        }
    }

    public function test_catalog_sync_preview_limited_diagnostic_row_failure_is_returned_as_conflict(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $firstProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-DIAG-OK',
            'name' => 'APCOM Diagnostic OK Product',
        ]);
        $failedProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-DIAG-FAIL',
            'name' => 'APCOM Diagnostic Failed Product',
        ]);

        Log::spy();

        $this->mock(CatalogSyncPreviewService::class, function ($mock) use ($firstProduct, $failedProduct): void {
            $mock
                ->shouldReceive('previewSupplierProduct')
                ->twice()
                ->andReturnUsing(function (SupplierProduct $supplierProduct) use ($firstProduct, $failedProduct): array {
                    if ($supplierProduct->is($failedProduct)) {
                        throw new RuntimeException('Limited row diagnostic failure');
                    }

                    $this->assertTrue($supplierProduct->is($firstProduct));

                    return [
                        'product_name' => 'APCOM Diagnostic OK Product',
                        'target_catalog_action' => 'create',
                        'reason' => 'New catalog product',
                        'result' => 'New catalog product will be created',
                        'conflict_reasons' => [],
                    ];
                });
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=preview_5')
            ->assertOk()
            ->assertSee('Catalog Sync Preview diagnostics OK')
            ->assertSee('Limited 5-row preview completed.')
            ->assertSee('failed_rows')
            ->assertSee('APC-DIAG-FAIL')
            ->assertSee('preview_generation_failed')
            ->assertSee('Limited row diagnostic failure');

        Log::shouldHaveReceived('info')
            ->with('Catalog Sync Preview diagnostic row preview starting.', Mockery::on(fn (array $context): bool => $context['supplier_product_id'] === $firstProduct->id
                && $context['row_index'] === 1))
            ->once();
        Log::shouldHaveReceived('info')
            ->with('Catalog Sync Preview diagnostic row preview completed.', Mockery::on(fn (array $context): bool => $context['supplier_product_id'] === $firstProduct->id
                && $context['target_catalog_action'] === 'create'))
            ->once();
        Log::shouldHaveReceived('warning')
            ->with('Catalog Sync Preview diagnostic row preview failed.', Mockery::on(fn (array $context): bool => $context['supplier_product_id'] === $failedProduct->id
                && $context['row_index'] === 2
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'Limited row diagnostic failure'))
            ->once();
    }

    public function test_catalog_sync_preview_step_failure_is_visible_to_admin(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $this->supplierProduct($supplier);

        Log::spy();

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock
                ->shouldReceive('previewSupplierProduct')
                ->once()
                ->andThrow(new RuntimeException('One-row diagnostic failure'));
        });

        $this
            ->get(CatalogSyncPreview::getUrl().'?diagnostic_step=preview_one')
            ->assertOk()
            ->assertSee('Catalog Sync Preview diagnostics FAILED')
            ->assertSee('Step: preview_one')
            ->assertSee('RuntimeException')
            ->assertSee('One-row diagnostic failure');

        Log::shouldHaveReceived('error')
            ->with('Catalog Sync Preview diagnostic step failed.', Mockery::on(fn (array $context): bool => $context['step'] === 'preview_one'
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'One-row diagnostic failure'))
            ->once();
    }

    public function test_catalog_sync_preview_initial_page_limits_rows_before_preview_generation(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);

        for ($index = 1; $index <= 75; $index++) {
            $this->supplierProduct($supplier, [
                'supplier_sku' => 'APC-LIMIT-'.$index,
                'name' => 'APCOM Limited Product '.$index,
                'ean' => '9900000000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ]);
        }

        Log::spy();

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('APCOM Limited Product 50')
            ->assertDontSee('APCOM Limited Product 51');

        Log::shouldHaveReceived('info')
            ->with('Catalog sync preview generated.', Mockery::on(fn (array $context): bool => $context['supplier_id'] === $supplier->id
                && $context['limit'] === 50
                && $context['rows_selected'] === 75
                && $context['rows_processed'] === 50
                && $context['rows_rendered'] === 50
                && isset($context['duration_ms'], $context['query_count'])))
            ->once();
    }

    public function test_catalog_sync_preview_initial_page_defaults_to_apcom_when_available(): void
    {
        $this->actingAsSupplierManager();

        $apcom = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier']);

        $this->supplierProduct($apcom, [
            'supplier_sku' => 'APC-DEFAULT-001',
            'name' => 'APCOM Default Preview Product',
        ]);
        $this->supplierProduct($other, [
            'supplier_sku' => 'OTHER-DEFAULT-001',
            'name' => 'Other Supplier Preview Product',
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('APCOM Default Preview Product')
            ->assertDontSee('Other Supplier Preview Product');
    }

    private function actingAsSupplierManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('manager');

        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => fake()->unique()->bothify('SUP-####??'),
            'ean' => fake()->unique()->numerify('#############'),
            'mpn' => fake()->unique()->bothify('MPN-####??'),
            'name' => 'Catalog Sync Preview Test Product',
            'brand_name' => 'Preview Brand',
            'category_name' => 'Preview Category',
            'price' => 100,
            'quantity' => 5,
            'currency' => 'EUR',
            'raw_data' => [
                'image' => [
                    'https://example.test/image-1.jpg',
                    'https://example.test/image-2.jpg',
                ],
            ],
            'payload_hash' => fake()->unique()->sha1(),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }
}
