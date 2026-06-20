<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Models\Supplier;
use App\Models\SupplierExclusionRule;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Products\CatalogSyncPreviewService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_catalog_sync_preview_page_renders_filter_ui_and_query_only_section_without_preview_generation(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create([
            'company_name' => 'APCOM',
            'slug' => 'apcom',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Supplier Product Must Not Render',
        ]);

        $this->mock(CatalogSyncPreviewService::class, function ($mock): void {
            $mock->shouldNotReceive('preview');
            $mock->shouldNotReceive('previewSupplierProduct');
            $mock->shouldNotReceive('traceSupplierProductPreview');
        });

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Batch')
            ->assertSee('Supplier')
            ->assertSee('Catalog action')
            ->assertSee('Quick filter')
            ->assertSee('Catalog Sync Preview UI OK')
            ->assertSee('Catalog Sync Preview Query Only OK')
            ->assertSee('data-catalog-sync-preview-scroll-panel', false)
            ->assertSee('class="max-w-full overflow-x-auto overflow-y-auto pb-4"', false)
            ->assertSee('overflow-x-auto', false)
            ->assertSee('overflow-y-auto', false)
            ->assertSee('max-height: 70vh', false)
            ->assertSee('padding-bottom: 1rem', false)
            ->assertSee('min-w-[2400px]', false)
            ->assertSee('min-width: 2400px', false)
            ->assertSee('sticky top-0', false)
            ->assertSee('z-30', false)
            ->assertSee('shadow-sm', false)
            ->assertSee('max-w-[22rem]', false)
            ->assertSee('Supplier Product Must Not Render');
    }

    public function test_catalog_sync_preview_query_only_supplier_filter_works(): void
    {
        $this->actingAsSupplierManager();

        $apcom = Supplier::factory()->create(['company_name' => 'APCOM']);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier']);

        $this->supplierProduct($apcom, ['name' => 'APCOM Query Only Product']);
        $this->supplierProduct($other, ['name' => 'Other Query Only Product']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $apcom->id)
            ->assertSee('APCOM Query Only Product')
            ->assertDontSee('Other Query Only Product');
    }

    public function test_catalog_sync_preview_query_only_limit_is_applied_before_rendering(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();

        for ($index = 1; $index <= 60; $index++) {
            $this->supplierProduct($supplier, [
                'name' => 'Query Limit Product '.$index,
                'supplier_sku' => 'QLIMIT-'.$index,
            ]);
        }

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.limit', 50)
            ->assertSee('Query Limit Product 50')
            ->assertDontSee('Query Limit Product 51');
    }

    public function test_catalog_sync_preview_query_only_search_filter_matches_sku_ean_and_name(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Needle Name Product',
            'supplier_sku' => 'SKU-NEEDLE',
            'ean' => '1234567890001',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'EAN Matched Product',
            'supplier_sku' => 'SKU-OTHER',
            'ean' => '9999999999999',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Hidden Query Product',
            'supplier_sku' => 'SKU-HIDDEN',
            'ean' => '1234567890002',
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.search', 'NEEDLE')
            ->assertSee('Needle Name Product')
            ->assertDontSee('Hidden Query Product');

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.search', '9999999999999')
            ->assertSee('EAN Matched Product')
            ->assertDontSee('Hidden Query Product');
    }

    public function test_catalog_sync_preview_query_only_does_not_render_raw_data(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Raw Data Safe Product',
            'raw_data' => ['secret_raw_payload_marker' => 'do-not-render'],
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Raw Data Safe Product')
            ->assertDontSee('secret_raw_payload_marker')
            ->assertDontSee('do-not-render');
    }

    public function test_catalog_sync_preview_query_only_failure_renders_error_panel(): void
    {
        $this->actingAsSupplierManager();

        config(['services.catalog_sync_preview.force_query_only_failure' => true]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Supplier products query failed.')
            ->assertSee('Forced query-only failure.')
            ->assertSee('Catalog Sync Preview Query Only OK');
    }

    public function test_catalog_sync_preview_query_only_pricing_renders(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Pricing Query Product',
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

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Supplier Cost')
            ->assertSee('Pricing Rule')
            ->assertSee('Margin Type')
            ->assertSee('Margin Value')
            ->assertSee('Calculated Price')
            ->assertSee('Global Default')
            ->assertSee('percentage')
            ->assertSee('20%')
            ->assertSee('120.00 EUR');
    }

    public function test_catalog_sync_preview_query_only_pricing_rule_is_applied(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $category = Category::factory()->create([
            'name' => 'Video Cards',
            'slug' => 'video-cards',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Category Pricing Query Product',
            'category_name' => 'Components > Video Cards',
            'price' => 100,
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

        Livewire::test(CatalogSyncPreview::class)
            ->assertSee('Video Cards')
            ->assertSee('12%')
            ->assertSee('112.00 EUR');
    }

    public function test_catalog_sync_preview_query_only_pricing_error_does_not_crash_page(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $broken = $this->supplierProduct($supplier, [
            'name' => 'Broken Pricing Product',
            'price' => 100,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Healthy Pricing Product',
            'price' => 50,
        ]);

        PricingRule::query()->create([
            'name' => 'Global default margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);
        config(['services.catalog_sync_preview.force_pricing_failure_supplier_product_id' => $broken->id]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Broken Pricing Product')
            ->assertSee('Pricing Error')
            ->assertSee('Forced pricing failure.')
            ->assertSee('Healthy Pricing Product')
            ->assertSee('60.00 EUR');
    }

    public function test_catalog_sync_preview_query_only_zero_stock_exclusion_renders(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Zero Stock Excluded Product',
            'quantity' => 0,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Included Stock Product',
            'quantity' => 5,
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'Exclude zero stock',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
            'reason' => 'No stock products stay staged only',
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertSame(1, $summary['included']);
        $this->assertSame(1, $summary['excluded']);

        $result
            ->assertSee('Excluded Rows')
            ->assertSee('Zero Stock Excluded Product')
            ->assertSee('Included Stock Product')
            ->assertSee('Yes')
            ->assertSee('No')
            ->assertSee('Zero stock');
    }

    public function test_catalog_sync_preview_query_only_missing_ean_exclusion_renders(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Missing EAN Excluded Product',
            'ean' => null,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'EAN Included Product',
            'ean' => '1234567890123',
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'Exclude missing EAN',
            'is_active' => true,
            'exclude_missing_ean' => true,
            'priority' => 10,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertSame(1, $summary['included']);
        $this->assertSame(1, $summary['excluded']);

        $result
            ->assertSee('Missing EAN Excluded Product')
            ->assertSee('EAN Included Product')
            ->assertSee('Missing EAN');
    }

    public function test_catalog_sync_preview_query_only_page_renders_with_exclusions(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Renderable Exclusion Product',
            'quantity' => 0,
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'Renderable zero stock rule',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
        ]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Included Rows')
            ->assertSee('Excluded Rows')
            ->assertSee('Excluded')
            ->assertSee('Exclusion Reason')
            ->assertSee('Renderable Exclusion Product')
            ->assertSee('Zero stock');
    }

    public function test_catalog_sync_preview_query_only_exclusion_error_does_not_crash_page(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $broken = $this->supplierProduct($supplier, [
            'name' => 'Broken Exclusion Product',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Healthy Exclusion Product',
        ]);

        config(['services.catalog_sync_preview.force_exclusion_failure_supplier_product_id' => $broken->id]);

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk()
            ->assertSee('Broken Exclusion Product')
            ->assertSee('Exclusion Error')
            ->assertSee('Forced exclusion failure.')
            ->assertSee('Healthy Exclusion Product');
    }

    public function test_catalog_sync_preview_query_only_ean_match_renders(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Existing EAN Catalog Product',
            'ean' => '879961009533',
            'mpn' => null,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Supplier EAN Product',
            'ean' => '879961009533',
            'mpn' => null,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertSame($product->id, $rows[0]['matched_product_id']);
        $this->assertSame('Existing EAN Catalog Product', $rows[0]['matched_product_name']);
        $this->assertSame('ean', $rows[0]['match_type']);
        $this->assertSame('exact', $rows[0]['match_confidence']);
        $this->assertSame(1, $summary['matched']);

        $result
            ->assertSee('Matched Product ID')
            ->assertSee('Matched Product')
            ->assertSee('Match Type')
            ->assertSee('Match Confidence')
            ->assertSee('Existing EAN Catalog Product');
    }

    public function test_catalog_sync_preview_query_only_supplier_sku_matches_only_within_same_supplier(): void
    {
        $this->actingAsSupplierManager();

        $supplierA = Supplier::factory()->create();
        $supplierB = Supplier::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Supplier A Offer Product',
            'ean' => null,
            'mpn' => null,
        ]);
        ProductSupplierOffer::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplierA->id,
            'supplier_product_id' => null,
            'supplier_sku' => 'SHARED-SUP-SKU',
            'price' => 25,
            'quantity' => 10,
            'currency' => 'EUR',
        ]);

        $this->supplierProduct($supplierA, [
            'name' => 'Supplier A Shared SKU Product',
            'supplier_sku' => 'SHARED-SUP-SKU',
            'ean' => null,
            'mpn' => null,
        ]);
        $this->supplierProduct($supplierB, [
            'name' => 'Supplier B Shared SKU Product',
            'supplier_sku' => 'SHARED-SUP-SKU',
            'ean' => null,
            'mpn' => null,
        ]);

        $supplierARows = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplierA->id)
            ->instance()
            ->queryOnlySupplierProducts()['rows'];
        $supplierBRows = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplierB->id)
            ->instance()
            ->queryOnlySupplierProducts()['rows'];

        $this->assertSame($product->id, $supplierARows[0]['matched_product_id']);
        $this->assertSame('supplier_sku', $supplierARows[0]['match_type']);
        $this->assertNull($supplierBRows[0]['matched_product_id']);
        $this->assertSame('no_match', $supplierBRows[0]['match_type']);
    }

    public function test_catalog_sync_preview_query_only_mpn_brand_match_renders(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $brand = Brand::factory()->create(['name' => 'ASUS', 'slug' => 'asus']);
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'name' => 'Existing ASUS MPN Product',
            'ean' => null,
            'mpn' => 'ASUS-MPN-1',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Supplier ASUS MPN Product',
            'ean' => null,
            'mpn' => 'ASUS-MPN-1',
            'brand_name' => 'ASUS',
        ]);

        $rows = Livewire::test(CatalogSyncPreview::class)
            ->instance()
            ->queryOnlySupplierProducts()['rows'];

        $this->assertSame($product->id, $rows[0]['matched_product_id']);
        $this->assertSame('Existing ASUS MPN Product', $rows[0]['matched_product_name']);
        $this->assertSame('mpn_brand', $rows[0]['match_type']);
    }

    public function test_catalog_sync_preview_query_only_unmatched_product_renders(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Unmatched Supplier Product',
            'ean' => null,
            'mpn' => null,
            'supplier_sku' => 'UNMATCHED-SKU',
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertNull($rows[0]['matched_product_id']);
        $this->assertNull($rows[0]['matched_product_name']);
        $this->assertSame('no_match', $rows[0]['match_type']);
        $this->assertSame('none', $rows[0]['match_confidence']);
        $this->assertSame(1, $summary['unmatched']);
    }

    public function test_catalog_sync_preview_query_only_matching_failure_does_not_crash_page(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $broken = $this->supplierProduct($supplier, [
            'name' => 'Broken Matching Product',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Healthy Matching Product',
        ]);

        config(['services.catalog_sync_preview.force_matching_failure_supplier_product_id' => $broken->id]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $brokenRow = collect($rows)->firstWhere('supplier_product_id', $broken->id);

        $this->assertSame('error', $brokenRow['match_type']);
        $this->assertStringContainsString('matching_check_failed', $brokenRow['match_confidence']);
        $this->assertSame(1, $summary['match_errors']);

        $result
            ->assertSee('Broken Matching Product')
            ->assertSee('Healthy Matching Product')
            ->assertSee('matching_check_failed');
    }

    public function test_catalog_sync_preview_query_only_matching_does_not_modify_catalog_data(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Read Only Matched Product',
            'ean' => '1234567890123',
            'price' => 99,
            'quantity' => 3,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Read Only Supplier Product',
            'ean' => '1234567890123',
            'price' => 10,
            'quantity' => 15,
        ]);

        $beforeProduct = $product->fresh()->only(['name', 'ean', 'price', 'quantity', 'supplier_id', 'supplier_sku']);
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['name', 'ean', 'price', 'quantity', 'status']);

        Livewire::test(CatalogSyncPreview::class)
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame($beforeProduct, $product->fresh()->only(['name', 'ean', 'price', 'quantity', 'supplier_id', 'supplier_sku']));
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(['name', 'ean', 'price', 'quantity', 'status']));
    }

    public function test_catalog_sync_preview_query_only_unmatched_valid_product_gets_create_action(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Create Action Supplier Product',
            'ean' => null,
            'mpn' => null,
            'supplier_sku' => 'CREATE-ACTION-SKU',
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertSame('CREATE', $rows[0]['sync_action']);
        $this->assertSame('new_catalog_product', $rows[0]['sync_reason']);
        $this->assertSame(1, $summary['create_rows']);

        $result
            ->assertSee('Sync Action')
            ->assertSee('Sync Reason')
            ->assertSee('Create Rows')
            ->assertSee('CREATE')
            ->assertSee('new_catalog_product');
    }

    public function test_catalog_sync_preview_query_only_matched_valid_product_gets_update_action(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'Update Action Catalog Product',
            'ean' => '5555555555555',
            'mpn' => null,
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Update Action Supplier Product',
            'ean' => '5555555555555',
            'mpn' => null,
            'price' => 20,
            'quantity' => 8,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertSame('UPDATE', $rows[0]['sync_action']);
        $this->assertSame('matched_catalog_product_can_be_updated', $rows[0]['sync_reason']);
        $this->assertSame(1, $summary['update_rows']);
    }

    public function test_catalog_sync_preview_query_only_excluded_product_gets_skip_action(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Skip Action Excluded Product',
            'quantity' => 0,
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'Skip action zero stock rule',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertSame('SKIP', $rows[0]['sync_action']);
        $this->assertSame('excluded_by_rule', $rows[0]['sync_reason']);
        $this->assertSame(1, $summary['skip_rows']);
    }

    public function test_catalog_sync_preview_query_only_ambiguous_match_gets_conflict_action(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'Conflict Action Catalog Product A',
            'ean' => '6666666666666',
            'mpn' => null,
        ]);
        Product::factory()->create([
            'name' => 'Conflict Action Catalog Product B',
            'ean' => '6666666666666',
            'mpn' => null,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Conflict Action Supplier Product',
            'ean' => '6666666666666',
            'mpn' => null,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];

        $this->assertSame('CONFLICT', $rows[0]['sync_action']);
        $this->assertSame('multiple_possible_matches', $rows[0]['sync_reason']);
        $this->assertSame(1, $summary['conflict_rows']);
    }

    public function test_catalog_sync_preview_query_only_action_evaluation_failure_does_not_crash_page(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $broken = $this->supplierProduct($supplier, [
            'name' => 'Broken Action Product',
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Healthy Action Product',
        ]);

        config(['services.catalog_sync_preview.force_action_failure_supplier_product_id' => $broken->id]);

        $result = Livewire::test(CatalogSyncPreview::class);
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];
        $summary = $result->instance()->queryOnlySupplierProducts()['summary'];
        $brokenRow = collect($rows)->firstWhere('supplier_product_id', $broken->id);

        $this->assertSame('ERROR', $brokenRow['sync_action']);
        $this->assertSame('action_preview_failed', $brokenRow['sync_reason']);
        $this->assertSame(1, $summary['error_rows']);

        $result
            ->assertSee('Broken Action Product')
            ->assertSee('Healthy Action Product')
            ->assertSee('action_preview_failed');
    }

    public function test_catalog_sync_preview_query_only_sync_action_preview_does_not_modify_catalog_or_supplier_data(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Read Only Action Product',
            'ean' => '7777777777777',
            'price' => 88,
            'quantity' => 2,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Read Only Action Supplier Product',
            'ean' => '7777777777777',
            'price' => 44,
            'quantity' => 10,
        ]);

        $beforeProductCount = Product::query()->count();
        $beforeSupplierProductCount = SupplierProduct::query()->count();
        $beforeProduct = $product->fresh()->only(['name', 'ean', 'price', 'quantity', 'supplier_id', 'supplier_sku']);
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['name', 'ean', 'price', 'quantity', 'status']);

        Livewire::test(CatalogSyncPreview::class)
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame($beforeProductCount, Product::query()->count());
        $this->assertSame($beforeSupplierProductCount, SupplierProduct::query()->count());
        $this->assertSame($beforeProduct, $product->fresh()->only(['name', 'ean', 'price', 'quantity', 'supplier_id', 'supplier_sku']));
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(['name', 'ean', 'price', 'quantity', 'status']));
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
