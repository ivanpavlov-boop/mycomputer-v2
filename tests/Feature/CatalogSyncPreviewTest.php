<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Models\Brand;
use App\Models\CatalogSyncBatch;
use App\Models\CatalogSyncLog;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductImage;
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

    public function test_catalog_sync_safety_flags_have_safe_defaults(): void
    {
        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

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
            ->assertSee('data-catalog-sync-preview-summary-grid', false)
            ->assertSee('grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5', false)
            ->assertSee('grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr))', false)
            ->assertSee('Create Rows')
            ->assertSee('Update Rows')
            ->assertSee('Skip Rows')
            ->assertSee('Conflict Rows')
            ->assertSee('Error Rows')
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

    public function test_catalog_sync_preview_action_filter_create_with_zero_create_rows_renders_no_rows(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'Existing CREATE Filter Catalog Product',
            'ean' => '1212121212121',
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Update Only Supplier Product',
            'ean' => '1212121212121',
            'price' => 20,
            'quantity' => 5,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.action', 'CREATE');
        $preview = $result->instance()->queryOnlySupplierProducts();

        $this->assertSame(0, $preview['summary']['create_rows']);
        $this->assertSame(1, $preview['summary']['update_rows']);
        $this->assertSame([], $preview['rows']);

        $result
            ->assertDontSee('Update Only Supplier Product')
            ->assertSee('Sync Selected CREATE Products (0)')
            ->assertSee('data-selected-create-sync-disabled="true"', false)
            ->assertSee('No supplier products match the query-only filters.');
    }

    public function test_catalog_sync_preview_action_filter_update_renders_only_update_rows(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'Existing UPDATE Filter Catalog Product',
            'ean' => '2323232323232',
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Visible UPDATE Supplier Product',
            'ean' => '2323232323232',
            'price' => 30,
            'quantity' => 7,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Hidden CREATE Supplier Product',
            'ean' => null,
            'mpn' => null,
            'supplier_sku' => 'HIDDEN-CREATE-ACTION',
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.action', 'UPDATE');
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('UPDATE', $rows[0]['sync_action']);
        $this->assertSame('Visible UPDATE Supplier Product', $rows[0]['name']);

        $result
            ->assertSee('Visible UPDATE Supplier Product')
            ->assertDontSee('Hidden CREATE Supplier Product');
    }

    public function test_catalog_sync_preview_action_filter_skip_renders_only_skip_rows(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Visible SKIP Supplier Product',
            'quantity' => 0,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Hidden CREATE From Skip Filter',
            'supplier_sku' => 'HIDDEN-CREATE-SKIP-FILTER',
            'ean' => null,
            'mpn' => null,
            'quantity' => 5,
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'Action filter zero stock rule',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.action', 'SKIP');
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('SKIP', $rows[0]['sync_action']);
        $this->assertSame('Visible SKIP Supplier Product', $rows[0]['name']);

        $result
            ->assertSee('Visible SKIP Supplier Product')
            ->assertDontSee('Hidden CREATE From Skip Filter');
    }

    public function test_catalog_sync_preview_without_action_filter_renders_all_action_rows(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'All Actions Update Product',
            'ean' => '3434343434343',
            'mpn' => null,
            'price' => 10,
            'quantity' => 1,
        ]);
        Product::factory()->create(['ean' => '4545454545454', 'mpn' => null]);
        Product::factory()->create(['ean' => '4545454545454', 'mpn' => null]);

        $this->supplierProduct($supplier, [
            'name' => 'All Actions CREATE Supplier Product',
            'supplier_sku' => 'ALL-ACTIONS-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'All Actions UPDATE Supplier Product',
            'ean' => '3434343434343',
            'mpn' => null,
            'price' => 20,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'All Actions SKIP Supplier Product',
            'quantity' => 0,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'All Actions CONFLICT Supplier Product',
            'ean' => '4545454545454',
            'mpn' => null,
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'All actions zero stock rule',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
        ]);

        $rows = Livewire::test(CatalogSyncPreview::class)
            ->instance()
            ->queryOnlySupplierProducts()['rows'];

        $this->assertCount(4, $rows);
        $this->assertSame(['CONFLICT', 'CREATE', 'SKIP', 'UPDATE'], collect($rows)->pluck('sync_action')->sort()->values()->all());
    }

    public function test_catalog_sync_preview_action_filter_works_with_existing_filters(): void
    {
        $this->actingAsSupplierManager();

        $apcom = Supplier::factory()->create(['company_name' => 'APCOM']);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier']);

        $this->supplierProduct($apcom, [
            'name' => 'APCOM CREATE Combined Filter Product',
            'supplier_sku' => 'APCOM-CREATE-COMBINED',
            'ean' => null,
            'mpn' => null,
        ]);
        $this->supplierProduct($other, [
            'name' => 'Other CREATE Combined Filter Product',
            'supplier_sku' => 'OTHER-CREATE-COMBINED',
            'ean' => null,
            'mpn' => null,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $apcom->id)
            ->set('filters.search', 'COMBINED')
            ->set('filters.action', 'CREATE');
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('CREATE', $rows[0]['sync_action']);
        $this->assertSame('APCOM CREATE Combined Filter Product', $rows[0]['name']);

        $result
            ->assertSee('APCOM CREATE Combined Filter Product')
            ->assertDontSee('Other CREATE Combined Filter Product');
    }

    public function test_catalog_sync_preview_create_filter_keeps_create_rows_selectable(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Selectable CREATE Filter Product',
            'supplier_sku' => 'SELECTABLE-CREATE-FILTER',
            'ean' => null,
            'mpn' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.action', 'CREATE')
            ->assertSee('Selectable CREATE Filter Product')
            ->assertSee('wire:model.live="selectedSupplierProductIds"', false)
            ->assertDontSee('disabled="disabled"', false);
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

    public function test_catalog_sync_preview_selected_valid_create_row_creates_product(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Manual Create Supplier Product',
            'supplier_sku' => 'MANUAL-CREATE-001',
            'ean' => null,
            'mpn' => null,
            'price' => 100,
            'quantity' => 6,
            'raw_data' => [
                'image' => [
                    'https://example.test/manual-create.jpg',
                ],
            ],
        ]);

        PricingRule::query()->create([
            'name' => 'Global default margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 1)
            ->assertSet('lastManualSyncResult.skipped', 0)
            ->assertSet('lastManualSyncResult.failed', 0);

        $product = Product::query()->where('supplier_sku', 'MANUAL-CREATE-001')->first();
        $batch = CatalogSyncBatch::query()->first();

        $this->assertNotNull($product);
        $this->assertNotNull($batch);
        $this->assertNotEmpty($batch->batch_uuid);
        $this->assertSame(CatalogSyncBatch::MODE_MANUAL_SELECTED_CREATE, $batch->mode);
        $this->assertSame(CatalogSyncBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->selected_count);
        $this->assertSame(1, $batch->created_count);
        $this->assertSame(0, $batch->updated_count);
        $this->assertSame(0, $batch->skipped_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertSame(Product::SOURCE_SUPPLIER_IMPORT, $product->source);
        $this->assertSame(Product::PRICE_SOURCE_SUPPLIER_IMPORT, $product->price_source);
        $this->assertSame($supplier->id, $product->supplier_id);
        $this->assertSame('120.00', $product->price);
        $this->assertSame('120.00', $product->regular_price);
        $this->assertSame('100.00', $product->purchase_price);
        $this->assertSame(6, $product->quantity);
        $this->assertFalse((bool) $product->active);
        $this->assertDatabaseHas('product_supplier_offers', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_sku' => 'MANUAL-CREATE-001',
        ]);
        $this->assertDatabaseHas('product_sync_logs', [
            'product_id' => $product->id,
            'supplier_product_id' => $supplierProduct->id,
            'action' => 'created',
            'strategy' => 'manual_selected_create',
        ]);
        $auditLog = CatalogSyncLog::query()
            ->where('catalog_sync_batch_id', $batch->id)
            ->where('supplier_product_id', $supplierProduct->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame(CatalogSyncLog::ACTION_CREATE, $auditLog->action);
        $this->assertSame(CatalogSyncLog::STATUS_SUCCESS, $auditLog->status);
        $this->assertSame('created', $auditLog->reason);
        $this->assertNull($auditLog->old_values);
        $this->assertSame($product->id, $auditLog->new_values['product_id']);
        $this->assertSame($supplierProduct->id, $auditLog->new_values['supplier_product_id']);
        $this->assertSame($product->id, $supplierProduct->fresh()->product_id);
        $this->assertSame('synced', $supplierProduct->fresh()->status);
        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_catalog_sync_preview_selected_create_is_blocked_when_feature_flag_disabled(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.create_enabled' => false]);

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Disabled Create Supplier Product',
            'supplier_sku' => 'DISABLED-CREATE-001',
            'ean' => null,
            'mpn' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 0)
            ->assertSet('lastManualSyncResult.skipped', 1)
            ->assertSet('lastManualSyncResult.failed', 0)
            ->assertSet('lastManualSyncResult.batch_id', null);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, CatalogSyncBatch::query()->count());
        $this->assertSame(0, CatalogSyncLog::query()->count());
        $this->assertNull($supplierProduct->fresh()->product_id);
        $this->assertSame('new', $supplierProduct->fresh()->status);
    }

    public function test_catalog_sync_preview_selected_excluded_row_is_skipped(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Excluded Manual Create Product',
            'quantity' => 0,
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'Skip selected zero stock rule',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 0)
            ->assertSet('lastManualSyncResult.skipped', 1)
            ->assertSet('lastManualSyncResult.failed', 0);

        $this->assertSame(0, Product::query()->count());
        $this->assertNull($supplierProduct->fresh()->product_id);
        $this->assertSame('new', $supplierProduct->fresh()->status);
        $this->assertDatabaseHas('catalog_sync_batches', [
            'mode' => CatalogSyncBatch::MODE_MANUAL_SELECTED_CREATE,
            'selected_count' => 1,
            'created_count' => 0,
            'skipped_count' => 1,
            'failed_count' => 0,
        ]);
        $this->assertDatabaseHas('catalog_sync_logs', [
            'supplier_product_id' => $supplierProduct->id,
            'action' => CatalogSyncLog::ACTION_CREATE,
            'status' => CatalogSyncLog::STATUS_SKIPPED,
        ]);
    }

    public function test_catalog_sync_preview_selected_matched_update_row_is_skipped_and_existing_product_is_not_updated(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Existing Matched Product',
            'ean' => '8888888888888',
            'price' => 50,
            'quantity' => 2,
            'supplier_id' => null,
            'supplier_sku' => null,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Selected Update Supplier Product',
            'ean' => '8888888888888',
            'price' => 200,
            'quantity' => 20,
        ]);

        $before = $product->fresh()->only(['name', 'ean', 'price', 'quantity', 'supplier_id', 'supplier_sku']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 0)
            ->assertSet('lastManualSyncResult.skipped', 1)
            ->assertSet('lastManualSyncResult.failed', 0);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame($before, $product->fresh()->only(['name', 'ean', 'price', 'quantity', 'supplier_id', 'supplier_sku']));
        $this->assertNull($supplierProduct->fresh()->product_id);
        $this->assertDatabaseHas('catalog_sync_logs', [
            'supplier_product_id' => $supplierProduct->id,
            'product_id' => null,
            'action' => CatalogSyncLog::ACTION_CREATE,
            'status' => CatalogSyncLog::STATUS_SKIPPED,
        ]);
    }

    public function test_catalog_sync_preview_selected_conflict_row_is_skipped(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create(['ean' => '9999999999999', 'mpn' => null]);
        Product::factory()->create(['ean' => '9999999999999', 'mpn' => null]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Selected Conflict Supplier Product',
            'ean' => '9999999999999',
            'mpn' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 0)
            ->assertSet('lastManualSyncResult.skipped', 1)
            ->assertSet('lastManualSyncResult.failed', 0);

        $this->assertSame(2, Product::query()->count());
        $this->assertNull($supplierProduct->fresh()->product_id);
    }

    public function test_catalog_sync_preview_selected_create_failure_does_not_stop_other_selected_rows(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $broken = $this->supplierProduct($supplier, [
            'name' => 'Broken Selected Create Product',
            'supplier_sku' => 'BROKEN-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);
        $healthy = $this->supplierProduct($supplier, [
            'name' => 'Healthy Selected Create Product',
            'supplier_sku' => 'HEALTHY-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);

        config(['services.catalog_sync_preview.force_manual_create_failure_supplier_product_id' => $broken->id]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$broken->id, $healthy->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 1)
            ->assertSet('lastManualSyncResult.skipped', 0)
            ->assertSet('lastManualSyncResult.failed', 1);

        $this->assertNull($broken->fresh()->product_id);
        $this->assertNotNull($healthy->fresh()->product_id);
        $this->assertDatabaseHas('products', [
            'supplier_sku' => 'HEALTHY-CREATE',
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
        ]);
        $this->assertDatabaseHas('catalog_sync_batches', [
            'mode' => CatalogSyncBatch::MODE_MANUAL_SELECTED_CREATE,
            'selected_count' => 2,
            'created_count' => 1,
            'skipped_count' => 0,
            'failed_count' => 1,
            'status' => CatalogSyncBatch::STATUS_PARTIAL,
        ]);
        $this->assertDatabaseHas('catalog_sync_logs', [
            'supplier_product_id' => $broken->id,
            'action' => CatalogSyncLog::ACTION_CREATE,
            'status' => CatalogSyncLog::STATUS_FAILED,
            'reason' => 'manual_create_failed',
        ]);
        $this->assertDatabaseHas('catalog_sync_logs', [
            'supplier_product_id' => $healthy->id,
            'action' => CatalogSyncLog::ACTION_CREATE,
            'status' => CatalogSyncLog::STATUS_SUCCESS,
        ]);
    }

    public function test_catalog_sync_preview_selected_create_does_not_import_images_or_update_unrelated_products(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $existingProduct = Product::factory()->create([
            'name' => 'Unrelated Existing Product',
            'price' => 77,
            'quantity' => 4,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'No Image Import Supplier Product',
            'supplier_sku' => 'NO-IMAGE-IMPORT',
            'ean' => null,
            'mpn' => null,
            'raw_data' => [
                'images' => [
                    'https://example.test/one.jpg',
                    'https://example.test/two.jpg',
                ],
            ],
        ]);

        $beforeExisting = $existingProduct->fresh()->only(['name', 'price', 'quantity', 'supplier_id', 'supplier_sku']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 1);

        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame($beforeExisting, $existingProduct->fresh()->only(['name', 'price', 'quantity', 'supplier_id', 'supplier_sku']));
    }

    public function test_catalog_sync_preview_renders_manual_create_selection_controls(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Selectable Create Product',
            'supplier_sku' => 'SELECTABLE-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);

        $response = $this->get(CatalogSyncPreview::getUrl());

        $response
            ->assertOk()
            ->assertSee('wire:model.live="filters.action"', false)
            ->assertSee('Manual CREATE sync')
            ->assertSee('Only eligible CREATE rows will be processed.')
            ->assertSee('Sync Selected CREATE Products (0)')
            ->assertSee('data-selected-create-sync-toolbar', false)
            ->assertSee('<button', false)
            ->assertSee('data-selected-create-sync-button', false)
            ->assertSee('data-selected-create-sync-disabled="true"', false)
            ->assertSee('border-green-600', false)
            ->assertSee('style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db;', false)
            ->assertSee('cursor: not-allowed;', false)
            ->assertDontSee('M6.75 18.75h10.5A3.75', false)
            ->assertSee('Select')
            ->assertSee('wire:model.live="selectedSupplierProductIds"', false);

        $content = $response->getContent();
        $toolbarStart = strpos($content, 'data-selected-create-sync-toolbar');
        $toolbarEnd = strpos($content, 'data-catalog-sync-preview-scroll-panel', $toolbarStart);

        $this->assertNotFalse($toolbarStart);
        $this->assertNotFalse($toolbarEnd);

        $toolbarHtml = substr($content, $toolbarStart, $toolbarEnd - $toolbarStart);

        $this->assertStringNotContainsString('<svg', $toolbarHtml);
        $this->assertStringNotContainsString('heroicon', $toolbarHtml);
    }

    public function test_catalog_sync_preview_renders_create_action_disabled_when_feature_flag_disabled(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.create_enabled' => false]);

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Disabled Button Create Product',
            'supplier_sku' => 'DISABLED-BUTTON-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->assertSee('Manual CREATE sync is disabled by configuration.')
            ->assertSee('Sync Selected CREATE Products (1)')
            ->assertSee('data-selected-create-sync-disabled="true"', false);
    }

    public function test_catalog_sync_preview_does_not_render_sync_all_or_automatic_actions(): void
    {
        $this->actingAsSupplierManager();

        $response = $this->get(CatalogSyncPreview::getUrl());

        $response
            ->assertOk()
            ->assertSee('Sync Selected UPDATE Price/Stock (0)')
            ->assertSee('Manual UPDATE sync is disabled by configuration.')
            ->assertSee('data-selected-update-sync-disabled="true"', false)
            ->assertSee('Sync All')
            ->assertSee('Automatic sync')
            ->assertSee('data-catalog-sync-feature-flag="sync-all"', false)
            ->assertSee('data-catalog-sync-feature-flag="automatic-sync"', false)
            ->assertDontSee('wire:click="syncAll', false)
            ->assertDontSee('wire:click="syncAutomatic', false);
    }

    public function test_catalog_sync_preview_selected_update_is_blocked_when_feature_flag_disabled(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => false]);

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '1212121212121',
            'price' => 50,
            'quantity' => 2,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Disabled Update Supplier Product',
            'ean' => '1212121212121',
            'price' => 150,
            'quantity' => 9,
        ]);
        $beforeProduct = $product->fresh()->only(['price', 'quantity', 'supplier_id', 'supplier_sku']);
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['product_id', 'status', 'synced_at', 'mapping_notes']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedUpdateProducts')
            ->assertSet('lastManualUpdateResult.updated', 0)
            ->assertSet('lastManualUpdateResult.skipped', 1)
            ->assertSet('lastManualUpdateResult.failed', 0)
            ->assertSet('lastManualUpdateResult.batch_id', null);

        $this->assertSame($beforeProduct, $product->fresh()->only(['price', 'quantity', 'supplier_id', 'supplier_sku']));
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(['product_id', 'status', 'synced_at', 'mapping_notes']));
        $this->assertSame(0, CatalogSyncBatch::query()->count());
        $this->assertSame(0, CatalogSyncLog::query()->count());
    }

    public function test_catalog_sync_preview_update_diff_preview_shows_commercial_changes_without_writes(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $product = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '4141414141415',
            'price' => 50,
            'regular_price' => 50,
            'final_selling_price' => 50,
            'purchase_price' => 40,
            'supplier_price_raw' => 40,
            'quantity' => 7,
            'stock_status' => 'in_stock',
            'name' => 'Curated Diff Product Name',
            'slug' => 'curated-diff-product-name',
            'short_description' => 'Curated short copy',
            'description' => 'Curated long copy',
            'meta_title' => 'Curated SEO title',
            'meta_description' => 'Curated SEO description',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Supplier Diff Product Name',
            'supplier_sku' => 'DIFF-PREVIEW-UPDATE',
            'ean' => '4141414141415',
            'price' => 100,
            'quantity' => 7,
            'external_availability_status' => 'available',
            'external_availability_label' => 'Available',
        ]);

        PricingRule::query()->create([
            'name' => 'Global diff preview margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        $beforeProduct = $product->fresh()->only([
            'name',
            'slug',
            'short_description',
            'description',
            'meta_title',
            'meta_description',
            'price',
            'quantity',
            'supplier_price_raw',
        ]);
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['product_id', 'status', 'synced_at', 'mapping_notes']);
        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE');
        $rows = $preview->instance()->queryOnlySupplierProducts()['rows'];
        $row = collect($rows)->firstWhere('supplier_product_id', $supplierProduct->id);

        $this->assertNotNull($row);
        $this->assertSame('UPDATE', $row['sync_action']);
        $this->assertSame(50.0, $row['update_diff']['current_price']);
        $this->assertSame(120.0, $row['update_diff']['new_price']);
        $this->assertSame(70.0, $row['update_diff']['price_change']);
        $this->assertTrue($row['update_diff']['price_changed']);
        $this->assertSame(7, $row['update_diff']['current_quantity']);
        $this->assertSame(7, $row['update_diff']['new_quantity']);
        $this->assertSame(0, $row['update_diff']['quantity_change']);
        $this->assertFalse($row['update_diff']['quantity_changed']);
        $this->assertSame(40.0, $row['update_diff']['current_supplier_cost']);
        $this->assertSame(100.0, $row['update_diff']['new_supplier_cost']);
        $this->assertArrayNotHasKey('current_name', $row['update_diff']);
        $this->assertArrayNotHasKey('new_name', $row['update_diff']);
        $this->assertArrayNotHasKey('current_description', $row['update_diff']);
        $this->assertArrayNotHasKey('new_description', $row['update_diff']);

        $preview
            ->assertSee('data-update-diff-preview', false)
            ->assertSee('Price +70.00 EUR')
            ->assertSee('Availability changed')
            ->assertSee('Supplier cost changed')
            ->assertSee('Offer changed')
            ->assertSee('Price: 50.00 EUR -&gt; 120.00 EUR', false)
            ->assertSee('Supplier cost: 40.00 EUR -&gt; 100.00 EUR', false)
            ->assertSee('Selected offer: - -&gt; Supplier Product #'.$supplierProduct->id.' / DIFF-PREVIEW-UPDATE', false)
            ->assertSee('whitespace-nowrap', false)
            ->assertDontSee('flex-wrap', false)
            ->assertDontSee('grid grid-cols-2', false)
            ->assertDontSee('Current quantity')
            ->assertDontSee('New quantity')
            ->assertDontSee('Quantity change')
            ->assertDontSee('Current product name')
            ->assertDontSee('New product name')
            ->assertDontSee('Current SEO')
            ->assertDontSee('New SEO')
            ->assertDontSee('Current description')
            ->assertDontSee('New description');

        $this->assertSame($beforeProduct, $product->fresh()->only(array_keys($beforeProduct)));
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(array_keys($beforeSupplierProduct)));
        $this->assertSame(0, CatalogSyncBatch::query()->count());
        $this->assertSame(0, CatalogSyncLog::query()->count());
        $this->assertSame(0, ProductSupplierOffer::query()->count());
    }

    public function test_catalog_sync_preview_update_confirmation_modal_shows_commercial_change_summary(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '4141414141416',
            'price' => 50,
            'quantity' => 7,
            'stock_status' => 'in_stock',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Modal Diff Supplier Product',
            'supplier_sku' => 'MODAL-DIFF-UPDATE',
            'ean' => '4141414141416',
            'price' => 100,
            'quantity' => 9,
            'external_availability_status' => 'available',
            'external_availability_label' => 'Available',
        ]);

        PricingRule::query()->create([
            'name' => 'Global modal diff margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('openUpdateConfirmationModal')
            ->assertSet('showUpdateConfirmationModal', true)
            ->assertSee('data-update-confirmation-change-summary', false)
            ->assertSee('Rows with price change')
            ->assertSee('Rows with stock change')
            ->assertSee('Rows with availability change')
            ->assertSee('Only commercial fields will be updated:')
            ->assertSee('Protected content will NOT be updated:')
            ->assertSee('Confirm UPDATE Price/Stock');

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, SupplierProduct::query()->count());
        $this->assertSame(0, CatalogSyncBatch::query()->count());
        $this->assertSame(0, CatalogSyncLog::query()->count());
    }

    public function test_catalog_sync_preview_update_button_opens_confirmation_modal_for_selected_update_row(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $product = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '4141414141414',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Modal Confirmation Supplier Product',
            'supplier_sku' => 'MODAL-CONFIRM-UPDATE',
            'ean' => '4141414141414',
            'price' => 150,
            'quantity' => 9,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('openUpdateConfirmationModal')
            ->assertSet('showUpdateConfirmationModal', true)
            ->assertSee('Confirm UPDATE Price/Stock sync')
            ->assertSee('You are about to update price/stock fields for selected products.')
            ->assertSee('Selected UPDATE rows')
            ->assertSee('1')
            ->assertSee('APCOM')
            ->assertSee('#'.$supplierProduct->id.' / MODAL-CONFIRM-UPDATE')
            ->assertSee('Product #'.$product->id)
            ->assertSee('Only commercial fields will be updated:')
            ->assertSee('price')
            ->assertSee('supplier cost')
            ->assertSee('quantity / stock')
            ->assertSee('availability')
            ->assertSee('selected supplier offer metadata')
            ->assertSee('Protected content will NOT be updated:')
            ->assertSee('product name')
            ->assertSee('slug')
            ->assertSee('SEO')
            ->assertSee('descriptions')
            ->assertSee('images')
            ->assertSee('categories')
            ->assertSee('attributes')
            ->assertSee('Cancel')
            ->assertSee('data-update-confirmation-cancel-button', false)
            ->assertSee('border: 1px solid #6b7280;', false)
            ->assertSee('Confirm UPDATE Price/Stock');

        $this->assertSame(0, CatalogSyncBatch::query()->count());
        $this->assertSame(0, CatalogSyncLog::query()->count());
    }

    public function test_catalog_sync_preview_update_confirmation_cancel_does_not_run_update(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '4242424242424',
            'price' => 50,
            'quantity' => 2,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Cancel Confirmation Supplier Product',
            'ean' => '4242424242424',
            'price' => 150,
            'quantity' => 9,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('openUpdateConfirmationModal')
            ->assertSet('showUpdateConfirmationModal', true)
            ->call('closeUpdateConfirmationModal')
            ->assertSet('showUpdateConfirmationModal', false)
            ->assertSet('lastManualUpdateResult', null);

        $this->assertSame('50.00', $product->fresh()->price);
        $this->assertSame(2, $product->fresh()->quantity);
        $this->assertSame(0, CatalogSyncBatch::query()->count());
        $this->assertSame(0, CatalogSyncLog::query()->count());
        $this->assertNull($supplierProduct->fresh()->product_id);
    }

    public function test_catalog_sync_preview_update_confirmation_confirm_runs_existing_update_method(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '4343434343434',
            'price' => 50,
            'quantity' => 2,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Confirm Existing Update Supplier Product',
            'supplier_sku' => 'CONFIRM-EXISTING-UPDATE',
            'ean' => '4343434343434',
            'price' => 150,
            'quantity' => 9,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('openUpdateConfirmationModal')
            ->assertSet('showUpdateConfirmationModal', true)
            ->call('confirmSelectedUpdateProducts')
            ->assertSet('showUpdateConfirmationModal', false)
            ->assertSet('lastManualUpdateResult.updated', 1)
            ->assertSet('lastManualUpdateResult.skipped', 0)
            ->assertSet('lastManualUpdateResult.failed', 0);

        $this->assertSame('150.00', $product->fresh()->price);
        $this->assertSame(9, $product->fresh()->quantity);
        $this->assertDatabaseHas('catalog_sync_batches', [
            'mode' => CatalogSyncBatch::MODE_MANUAL_SELECTED_UPDATE_PRICE_STOCK,
            'selected_count' => 1,
            'updated_count' => 1,
        ]);
        $this->assertDatabaseHas('catalog_sync_logs', [
            'supplier_product_id' => $supplierProduct->id,
            'product_id' => $product->id,
            'action' => CatalogSyncLog::ACTION_UPDATE,
            'status' => CatalogSyncLog::STATUS_SUCCESS,
        ]);
    }

    public function test_catalog_sync_preview_update_confirmation_modal_is_blocked_when_feature_flag_disabled(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => false]);

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '4444444444444',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Blocked Modal Supplier Product',
            'ean' => '4444444444444',
            'price' => 150,
            'quantity' => 9,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('openUpdateConfirmationModal')
            ->assertSet('showUpdateConfirmationModal', false)
            ->assertDontSee('Confirm UPDATE Price/Stock sync');

        $this->assertSame(0, CatalogSyncBatch::query()->count());
        $this->assertSame(0, CatalogSyncLog::query()->count());
    }

    public function test_catalog_sync_preview_create_flow_remains_direct_and_separate_from_update_modal(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Create Flow Still Direct Product',
            'supplier_sku' => 'CREATE-FLOW-DIRECT',
            'ean' => null,
            'mpn' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->assertSee('wire:click="syncSelectedCreateProducts"', false)
            ->assertSee('Sync Selected CREATE Products (1)')
            ->assertSee('Sync Selected UPDATE Price/Stock (0)')
            ->assertSet('showUpdateConfirmationModal', false);
    }

    public function test_catalog_sync_preview_exact_ean_update_row_is_eligible_when_feature_flag_enabled(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '3434343434343',
            'price' => 50,
            'quantity' => 2,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Eligible EAN Update Product',
            'ean' => '3434343434343',
            'price' => 150,
            'quantity' => 9,
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->instance()
            ->queryOnlySupplierProducts();
        $row = collect($preview['rows'])->firstWhere('supplier_product_id', $supplierProduct->id);

        $this->assertNotNull($row);
        $this->assertSame('UPDATE', $row['sync_action']);
        $this->assertSame('ean', $row['match_type']);
        $this->assertSame('exact', $row['match_confidence']);
        $this->assertTrue($row['manual_update_eligible']);
    }

    public function test_catalog_sync_preview_update_selection_updates_button_count(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '3131313131313',
        ]);
        Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '3232323232323',
        ]);
        $first = $this->supplierProduct($supplier, [
            'name' => 'First Selectable UPDATE Product',
            'ean' => '3131313131313',
            'price' => 150,
            'quantity' => 9,
        ]);
        $second = $this->supplierProduct($supplier, [
            'name' => 'Second Selectable UPDATE Product',
            'ean' => '3232323232323',
            'price' => 160,
            'quantity' => 10,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->assertSee('wire:model.live="selectedUpdateSupplierProductIds"', false)
            ->assertSee('value="'.$first->id.'"', false)
            ->assertSee('value="'.$second->id.'"', false)
            ->assertSee('data-update-select-disabled="false"', false)
            ->assertSee('Sync Selected UPDATE Price/Stock (0)')
            ->assertSee('data-selected-update-sync-disabled="true"', false)
            ->set('selectedUpdateSupplierProductIds', [$first->id])
            ->assertSee('Sync Selected UPDATE Price/Stock (1)')
            ->assertSee('data-selected-update-sync-disabled="false"', false)
            ->set('selectedUpdateSupplierProductIds', [$first->id, $second->id])
            ->assertSee('Sync Selected UPDATE Price/Stock (2)')
            ->assertSee('data-selected-update-sync-disabled="false"', false)
            ->set('selectedUpdateSupplierProductIds', [])
            ->assertSee('Sync Selected UPDATE Price/Stock (0)')
            ->assertSee('data-selected-update-sync-disabled="true"', false);
    }

    public function test_catalog_sync_preview_create_and_update_selections_are_independent(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        $create = $this->supplierProduct($supplier, [
            'name' => 'Independent CREATE Selection Product',
            'supplier_sku' => 'INDEPENDENT-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);
        Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '3333333333333',
        ]);
        $update = $this->supplierProduct($supplier, [
            'name' => 'Independent UPDATE Selection Product',
            'ean' => '3333333333333',
            'price' => 170,
            'quantity' => 11,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('selectedSupplierProductIds', [$create->id])
            ->assertSee('Sync Selected CREATE Products (1)')
            ->assertSee('Sync Selected UPDATE Price/Stock (0)')
            ->set('selectedUpdateSupplierProductIds', [$update->id])
            ->assertSee('Sync Selected CREATE Products (1)')
            ->assertSee('Sync Selected UPDATE Price/Stock (1)')
            ->set('selectedSupplierProductIds', [])
            ->assertSee('Sync Selected CREATE Products (0)')
            ->assertSee('Sync Selected UPDATE Price/Stock (1)')
            ->set('selectedUpdateSupplierProductIds', [])
            ->assertSee('Sync Selected CREATE Products (0)')
            ->assertSee('Sync Selected UPDATE Price/Stock (0)');
    }

    public function test_catalog_sync_preview_update_checkbox_is_disabled_when_feature_flag_disabled(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => false]);

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '3434343434344',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Disabled UPDATE Checkbox Product',
            'ean' => '3434343434344',
            'price' => 150,
            'quantity' => 9,
        ]);

        $component = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->assertSee('data-update-select-supplier-product-id="'.$supplierProduct->id.'"', false)
            ->assertSee('data-update-select-disabled="true"', false)
            ->assertSee('Sync Selected UPDATE Price/Stock (0)')
            ->assertSee('data-selected-update-sync-disabled="true"', false);
        $preview = $component->instance()->queryOnlySupplierProducts();
        $row = collect($preview['rows'])->firstWhere('supplier_product_id', $supplierProduct->id);

        $this->assertNotNull($row);
        $this->assertSame('UPDATE', $row['sync_action']);
        $this->assertFalse($row['manual_update_eligible']);
    }

    public function test_catalog_sync_preview_safe_non_ean_update_matches_are_eligible_when_feature_flag_enabled(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Safe Match Brand', 'slug' => 'safe-match-brand']);
        $supplierSkuProduct = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SAFE-SUPPLIER-SKU',
            'ean' => null,
            'mpn' => null,
            'price' => 50,
            'quantity' => 2,
        ]);
        $mpnBrandProduct = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'brand_id' => $brand->id,
            'ean' => null,
            'mpn' => 'SAFE-MPN-BRAND',
            'price' => 60,
            'quantity' => 3,
        ]);
        $linkedProduct = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'price' => 70,
            'quantity' => 4,
        ]);

        $supplierSku = $this->supplierProduct($supplier, [
            'name' => 'Safe Supplier SKU Update Product',
            'supplier_sku' => 'SAFE-SUPPLIER-SKU',
            'ean' => null,
            'mpn' => null,
            'price' => 150,
            'quantity' => 9,
        ]);
        $mpnBrand = $this->supplierProduct($supplier, [
            'name' => 'Safe MPN Brand Update Product',
            'ean' => null,
            'mpn' => 'SAFE-MPN-BRAND',
            'brand_name' => 'Safe Match Brand',
            'price' => 160,
            'quantity' => 10,
        ]);
        $linked = $this->supplierProduct($supplier, [
            'product_id' => $linkedProduct->id,
            'name' => 'Already Linked Update Product',
            'price' => 170,
            'quantity' => 11,
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.action', 'UPDATE')
            ->instance()
            ->queryOnlySupplierProducts();
        $rows = collect($preview['rows'])->keyBy('supplier_product_id');

        $this->assertSame($supplierSkuProduct->id, $rows[$supplierSku->id]['matched_product_id']);
        $this->assertSame('supplier_sku', $rows[$supplierSku->id]['match_type']);
        $this->assertTrue($rows[$supplierSku->id]['manual_update_eligible']);
        $this->assertSame($mpnBrandProduct->id, $rows[$mpnBrand->id]['matched_product_id']);
        $this->assertSame('mpn_brand', $rows[$mpnBrand->id]['match_type']);
        $this->assertTrue($rows[$mpnBrand->id]['manual_update_eligible']);
        $this->assertSame($linkedProduct->id, $rows[$linked->id]['matched_product_id']);
        $this->assertSame('manual_mapping', $rows[$linked->id]['match_type']);
        $this->assertTrue($rows[$linked->id]['manual_update_eligible']);
    }

    public function test_catalog_sync_preview_selected_update_changes_only_commercial_fields(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'priority' => 10]);
        $category = Category::factory()->create(['name' => 'Original Category']);
        $brand = Brand::factory()->create(['name' => 'Original Brand']);
        $product = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'ean' => '5656565656565',
            'name' => 'Curated Bulgarian Product Name',
            'slug' => 'curated-bulgarian-product-name',
            'short_description' => 'Curated short description',
            'description' => 'Curated full description',
            'meta_title' => 'Curated meta title',
            'meta_description' => 'Curated meta description',
            'lock_name' => true,
            'lock_seo' => true,
            'lock_descriptions' => true,
            'price' => 50,
            'regular_price' => 50,
            'final_selling_price' => 50,
            'purchase_price' => 40,
            'supplier_price_raw' => 40,
            'quantity' => 2,
            'stock_status' => 'in_stock',
            'supplier_id' => null,
            'supplier_sku' => null,
            'source_payload' => ['existing' => 'metadata'],
        ]);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/original.jpg',
            'sort_order' => 1,
            'is_primary' => true,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Supplier English Name Must Not Win',
            'supplier_sku' => 'UPDATE-PRICE-STOCK-001',
            'ean' => '5656565656565',
            'price' => 100,
            'quantity' => 7,
            'external_availability_status' => 'available',
            'external_availability_label' => 'Available',
            'brand_name' => 'Different Supplier Brand',
            'category_name' => 'Different Supplier Category',
            'raw_data' => [
                'images' => ['https://example.test/new-image.jpg'],
            ],
        ]);

        PricingRule::query()->create([
            'name' => 'Global update margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        $beforeContent = $product->fresh()->only([
            'name',
            'slug',
            'short_description',
            'description',
            'meta_title',
            'meta_description',
            'category_id',
            'brand_id',
            'lock_name',
            'lock_seo',
            'lock_descriptions',
        ]);
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['product_id', 'status', 'synced_at', 'mapping_notes']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedUpdateProducts')
            ->assertSet('lastManualUpdateResult.updated', 1)
            ->assertSet('lastManualUpdateResult.skipped', 0)
            ->assertSet('lastManualUpdateResult.failed', 0);

        $product->refresh();
        $batch = CatalogSyncBatch::query()->first();
        $log = CatalogSyncLog::query()->where('action', CatalogSyncLog::ACTION_UPDATE)->first();

        $this->assertNotNull($batch);
        $this->assertSame(CatalogSyncBatch::MODE_MANUAL_SELECTED_UPDATE_PRICE_STOCK, $batch->mode);
        $this->assertSame(CatalogSyncBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->selected_count);
        $this->assertSame(0, $batch->created_count);
        $this->assertSame(1, $batch->updated_count);
        $this->assertSame(0, $batch->skipped_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertNotNull($log);
        $this->assertSame(CatalogSyncLog::STATUS_SUCCESS, $log->status);
        $this->assertSame('updated_price_stock', $log->reason);
        $this->assertSame('50.00', $log->old_values['price']);
        $this->assertSame('120.00', $log->new_values['price']);
        $this->assertSame(2, $log->old_values['quantity']);
        $this->assertSame(7, $log->new_values['quantity']);
        $this->assertSame($beforeContent, $product->only(array_keys($beforeContent)));
        $this->assertSame('120.00', $product->price);
        $this->assertSame('120.00', $product->regular_price);
        $this->assertSame('120.00', $product->final_selling_price);
        $this->assertSame('100.00', $product->purchase_price);
        $this->assertSame('100.00', $product->supplier_price_raw);
        $this->assertSame(7, $product->quantity);
        $this->assertSame($supplier->id, $product->supplier_id);
        $this->assertSame('UPDATE-PRICE-STOCK-001', $product->supplier_sku);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, ProductImage::query()->count());
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(['product_id', 'status', 'synced_at', 'mapping_notes']));
        $this->assertDatabaseHas('product_supplier_offers', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_sku' => 'UPDATE-PRICE-STOCK-001',
            'is_preferred' => true,
        ]);
    }

    public function test_catalog_sync_preview_update_skips_unsafe_rows(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        Product::factory()->create(['name' => 'Similarity Only Product', 'ean' => null, 'mpn' => null]);
        $similar = $this->supplierProduct($supplier, [
            'name' => 'Similarity Only Product Extra',
            'ean' => null,
            'mpn' => null,
            'supplier_sku' => 'SIMILAR-ONLY',
        ]);
        Product::factory()->create(['ean' => '4545454545454', 'mpn' => null]);
        Product::factory()->create(['ean' => '4545454545454', 'mpn' => null]);
        $conflict = $this->supplierProduct($supplier, [
            'name' => 'Conflict Update Product',
            'ean' => '4545454545454',
            'mpn' => null,
        ]);
        $excludedProduct = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '7878787878787',
        ]);
        $excluded = $this->supplierProduct($supplier, [
            'name' => 'Excluded Update Product',
            'ean' => '7878787878787',
            'quantity' => 0,
        ]);
        SupplierExclusionRule::query()->create([
            'name' => 'Exclude zero stock updates',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
        ]);
        $missingProduct = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '8989898989898',
        ]);
        $missingData = $this->supplierProduct($supplier, [
            'name' => 'Missing Price Update Product',
            'ean' => '8989898989898',
            'price' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedUpdateSupplierProductIds', [$similar->id, $conflict->id, $excluded->id, $missingData->id])
            ->call('syncSelectedUpdateProducts')
            ->assertSet('lastManualUpdateResult.updated', 0)
            ->assertSet('lastManualUpdateResult.skipped', 4)
            ->assertSet('lastManualUpdateResult.failed', 0);

        $this->assertSame(5, Product::query()->count());
        $this->assertNull($similar->fresh()->product_id);
        $this->assertNull($conflict->fresh()->product_id);
        $this->assertNull($excluded->fresh()->product_id);
        $this->assertNull($missingData->fresh()->product_id);
        $this->assertNotNull($excludedProduct->fresh());
        $this->assertNotNull($missingProduct->fresh());
        $this->assertDatabaseHas('catalog_sync_batches', [
            'mode' => CatalogSyncBatch::MODE_MANUAL_SELECTED_UPDATE_PRICE_STOCK,
            'selected_count' => 4,
            'updated_count' => 0,
            'skipped_count' => 4,
            'failed_count' => 0,
        ]);
    }

    public function test_catalog_sync_preview_selected_update_failure_does_not_stop_other_rows(): void
    {
        $this->actingAsSupplierManager();

        config(['catalog_sync.update_enabled' => true]);

        $supplier = Supplier::factory()->create();
        $brokenProduct = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '1010101010101',
            'price' => 50,
            'quantity' => 2,
        ]);
        $healthyProduct = Product::factory()->create([
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'ean' => '2020202020202',
            'price' => 60,
            'quantity' => 3,
        ]);
        $broken = $this->supplierProduct($supplier, [
            'name' => 'Broken Update Supplier Product',
            'ean' => '1010101010101',
            'price' => 150,
            'quantity' => 9,
        ]);
        $healthy = $this->supplierProduct($supplier, [
            'name' => 'Healthy Update Supplier Product',
            'ean' => '2020202020202',
            'price' => 160,
            'quantity' => 10,
        ]);

        config(['services.catalog_sync_preview.force_manual_update_failure_supplier_product_id' => $broken->id]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedUpdateSupplierProductIds', [$broken->id, $healthy->id])
            ->call('syncSelectedUpdateProducts')
            ->assertSet('lastManualUpdateResult.updated', 1)
            ->assertSet('lastManualUpdateResult.skipped', 0)
            ->assertSet('lastManualUpdateResult.failed', 1);

        $this->assertSame('50.00', $brokenProduct->fresh()->price);
        $this->assertSame(2, $brokenProduct->fresh()->quantity);
        $this->assertSame('160.00', $healthyProduct->fresh()->price);
        $this->assertSame(10, $healthyProduct->fresh()->quantity);
        $this->assertDatabaseHas('catalog_sync_batches', [
            'mode' => CatalogSyncBatch::MODE_MANUAL_SELECTED_UPDATE_PRICE_STOCK,
            'selected_count' => 2,
            'updated_count' => 1,
            'skipped_count' => 0,
            'failed_count' => 1,
            'status' => CatalogSyncBatch::STATUS_PARTIAL,
        ]);
        $this->assertDatabaseHas('catalog_sync_logs', [
            'supplier_product_id' => $broken->id,
            'action' => CatalogSyncLog::ACTION_UPDATE,
            'status' => CatalogSyncLog::STATUS_FAILED,
            'reason' => 'manual_update_failed',
        ]);
        $this->assertDatabaseHas('catalog_sync_logs', [
            'supplier_product_id' => $healthy->id,
            'action' => CatalogSyncLog::ACTION_UPDATE,
            'status' => CatalogSyncLog::STATUS_SUCCESS,
        ]);
    }

    public function test_catalog_sync_preview_manual_create_button_shows_selected_count(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Selected Count Create Product',
            'supplier_sku' => 'SELECTED-COUNT-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->assertSee('Sync Selected CREATE Products (1)')
            ->assertSee('data-selected-create-sync-disabled="false"', false)
            ->assertSee('border: 1px solid #16a34a;', false)
            ->assertSee('cursor: pointer;', false);
    }

    public function test_catalog_sync_preview_create_candidate_scan_finds_rows_beyond_first_batch(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);

        for ($index = 1; $index <= 50; $index++) {
            $ean = '7700000000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);

            Product::factory()->create([
                'name' => 'Matched Catalog Product '.$index,
                'ean' => $ean,
                'mpn' => null,
                'price' => 10,
                'quantity' => 1,
            ]);

            $this->supplierProduct($supplier, [
                'name' => 'Matched Batch Product '.$index,
                'supplier_sku' => 'MATCHED-BATCH-'.$index,
                'ean' => $ean,
                'mpn' => null,
            ]);
        }

        $candidate = $this->supplierProduct($supplier, [
            'name' => 'CREATE Candidate Beyond First Batch',
            'supplier_sku' => 'CREATE-BEYOND-FIRST-BATCH',
            'ean' => null,
            'mpn' => null,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates');
        $preview = $result->instance()->queryOnlySupplierProducts();

        $this->assertSame(51, $preview['discovery']['scanned_rows']);
        $this->assertSame(1, $preview['discovery']['create_candidates_found']);
        $this->assertSame($candidate->id, $preview['rows'][0]['supplier_product_id']);
        $this->assertSame('CREATE', $preview['rows'][0]['sync_action']);

        $result
            ->assertSee('CREATE candidate scan')
            ->assertSee('CREATE Candidate Beyond First Batch')
            ->assertDontSee('Matched Batch Product 1');
    }

    public function test_catalog_sync_preview_create_candidate_scan_respects_supplier_filter(): void
    {
        $this->actingAsSupplierManager();

        $apcom = Supplier::factory()->create(['company_name' => 'APCOM']);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier']);

        $this->supplierProduct($apcom, [
            'name' => 'APCOM Discovery CREATE Candidate',
            'supplier_sku' => 'APCOM-DISCOVERY-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);
        $this->supplierProduct($other, [
            'name' => 'Other Discovery CREATE Candidate',
            'supplier_sku' => 'OTHER-DISCOVERY-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $apcom->id)
            ->set('filters.discovery_mode', 'create_candidates');
        $rows = $result->instance()->queryOnlySupplierProducts()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('APCOM Discovery CREATE Candidate', $rows[0]['name']);

        $result
            ->assertSee('APCOM Discovery CREATE Candidate')
            ->assertDontSee('Other Discovery CREATE Candidate');
    }

    public function test_catalog_sync_preview_create_candidate_scan_exposes_safe_limits(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();

        for ($index = 1; $index <= 60; $index++) {
            $this->supplierProduct($supplier, [
                'name' => 'Safe Limit CREATE Candidate '.$index,
                'supplier_sku' => 'SAFE-LIMIT-CREATE-'.$index,
                'ean' => null,
                'mpn' => null,
            ]);
        }

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(1000, $preview['discovery']['scan_limit']);
        $this->assertSame(50, $preview['discovery']['result_limit']);
        $this->assertSame(60, $preview['discovery']['scanned_rows']);
        $this->assertSame(60, $preview['discovery']['create_candidates_found']);
        $this->assertCount(50, $preview['rows']);
    }

    public function test_catalog_sync_preview_create_candidate_scan_supports_configurable_safe_limits(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();

        for ($index = 1; $index <= 120; $index++) {
            $this->supplierProduct($supplier, [
                'name' => 'Configurable Scan Candidate '.$index,
                'supplier_sku' => 'CONFIG-SCAN-'.$index,
                'ean' => null,
                'mpn' => null,
            ]);
        }

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->set('filters.discovery_scan_limit', 2000)
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(2000, $preview['discovery']['scan_limit']);
        $this->assertSame(120, $preview['discovery']['scanned_rows']);
        $this->assertSame(120, $preview['discovery']['create_candidates_found']);
        $this->assertCount(50, $preview['rows']);
    }

    public function test_catalog_sync_preview_create_candidate_scan_zero_results_shows_clear_message(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'ean' => '7878787878787',
            'mpn' => null,
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Only Update Discovery Product',
            'ean' => '7878787878787',
            'mpn' => null,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates');
        $preview = $result->instance()->queryOnlySupplierProducts();

        $this->assertSame(0, $preview['discovery']['create_candidates_found']);
        $this->assertSame([], $preview['rows']);

        $result
            ->assertSee('No eligible CREATE candidates found in the scanned supplier products.')
            ->assertSee('Sample rows that did not become CREATE')
            ->assertSee('Only Update Discovery Product');
    }

    public function test_catalog_sync_preview_create_candidate_scan_does_not_modify_data(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Read Only Discovery Candidate',
            'supplier_sku' => 'READ-ONLY-DISCOVERY',
            'ean' => null,
            'mpn' => null,
        ]);

        $beforeProductCount = Product::query()->count();
        $beforeSupplierProductCount = SupplierProduct::query()->count();
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['name', 'ean', 'mpn', 'supplier_sku', 'price', 'quantity', 'status', 'product_id']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame($beforeProductCount, Product::query()->count());
        $this->assertSame($beforeSupplierProductCount, SupplierProduct::query()->count());
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(['name', 'ean', 'mpn', 'supplier_sku', 'price', 'quantity', 'status', 'product_id']));
    }

    public function test_catalog_sync_preview_create_candidate_scan_rows_remain_selectable_and_enable_button(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Selectable Discovery CREATE Candidate',
            'supplier_sku' => 'SELECTABLE-DISCOVERY-CREATE',
            'ean' => null,
            'mpn' => null,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->assertSee('Selectable Discovery CREATE Candidate')
            ->assertSee('wire:model.live="selectedSupplierProductIds"', false)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->assertSee('Sync Selected CREATE Products (1)')
            ->assertSee('data-selected-create-sync-disabled="false"', false);
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_count_unmatched_missing_required_data(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Missing Required Discovery Product',
            'supplier_sku' => null,
            'ean' => null,
            'mpn' => null,
            'price' => 100,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates');
        $preview = $result->instance()->queryOnlySupplierProducts();

        $this->assertSame(0, $preview['discovery']['create_candidates_found']);
        $this->assertSame(1, $preview['discovery']['unmatched_not_create_reasons']['missing_required_data']);
        $this->assertSame(1, $preview['discovery']['unmatched_not_create_reasons']['missing_ean']);
        $this->assertSame(1, $preview['discovery']['unmatched_not_create_reasons']['missing_supplier_sku']);
        $this->assertSame(1, $preview['discovery']['skip_reason_summary']['missing_required_data']);
        $this->assertSame(1, $preview['discovery']['match_type_summary']['no_exact_match']);

        $result
            ->assertSee('Why no CREATE candidates?')
            ->assertSee('Missing required data')
            ->assertSee('Missing EAN')
            ->assertSee('Missing supplier SKU');
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_count_unmatched_excluded_row(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Excluded Discovery Product',
            'supplier_sku' => 'EXCLUDED-DISCOVERY',
            'ean' => null,
            'mpn' => null,
            'quantity' => 0,
        ]);

        SupplierExclusionRule::query()->create([
            'name' => 'Discovery zero stock rule',
            'is_active' => true,
            'exclude_zero_stock' => true,
            'priority' => 10,
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(0, $preview['discovery']['create_candidates_found']);
        $this->assertSame(1, $preview['discovery']['unmatched_not_create_reasons']['excluded']);
        $this->assertSame(1, $preview['discovery']['skip_reason_summary']['excluded']);
        $this->assertSame(1, $preview['discovery']['match_type_summary']['no_exact_match']);
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_count_exact_ean_match_separately(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'Exact EAN Catalog Product',
            'ean' => '5656565656565',
            'mpn' => null,
            'price' => 100,
            'quantity' => 5,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Exact EAN Supplier Product',
            'ean' => '5656565656565',
            'mpn' => null,
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(0, $preview['discovery']['create_candidates_found']);
        $this->assertSame(1, $preview['discovery']['match_type_summary']['exact_ean_match']);
        $this->assertSame(1, $preview['discovery']['skip_reason_summary']['matched_existing_product']);
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_break_down_supplier_mapping_matches(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'Existing Supplier Mapping Product',
            'ean' => null,
            'mpn' => null,
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'EXISTING-SUPPLIER-MAPPING',
            'price' => 100,
            'quantity' => 5,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Existing Supplier Mapping Supplier Product',
            'supplier_sku' => 'EXISTING-SUPPLIER-MAPPING',
            'ean' => null,
            'mpn' => null,
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(1, $preview['discovery']['match_type_summary']['existing_supplier_mapping']);
        $this->assertSame(0, $preview['discovery']['match_type_summary']['unknown_other']);
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_break_down_existing_product_offer_matches(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Existing Product Offer Product',
            'ean' => null,
            'mpn' => null,
            'supplier_id' => null,
            'supplier_sku' => null,
            'price' => 100,
            'quantity' => 5,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Existing Product Offer Supplier Product',
            'supplier_sku' => 'EXISTING-OFFER-SKU',
            'ean' => null,
            'mpn' => null,
        ]);

        ProductSupplierOffer::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_sku' => 'EXISTING-OFFER-SKU',
            'price' => 100,
            'quantity' => 5,
            'currency' => 'EUR',
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(1, $preview['discovery']['match_type_summary']['existing_product_offer']);
        $this->assertSame(0, $preview['discovery']['match_type_summary']['unknown_other']);
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_break_down_already_linked_supplier_products(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Already Linked Catalog Product',
            'ean' => null,
            'mpn' => null,
            'price' => 100,
            'quantity' => 5,
        ]);
        $this->supplierProduct($supplier, [
            'product_id' => $product->id,
            'name' => 'Already Linked Supplier Product',
            'supplier_sku' => 'ALREADY-LINKED-SKU',
            'ean' => null,
            'mpn' => null,
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(1, $preview['discovery']['match_type_summary']['already_linked_supplier_product']);
        $this->assertSame(0, $preview['discovery']['match_type_summary']['unknown_other']);
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_render_sample_rows_when_no_create_candidates(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $this->supplierProduct($supplier, [
            'name' => 'Sample Diagnostic Supplier Product',
            'supplier_sku' => null,
            'ean' => null,
            'mpn' => null,
        ]);

        $result = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates');
        $preview = $result->instance()->queryOnlySupplierProducts();

        $this->assertSame(0, $preview['discovery']['create_candidates_found']);
        $this->assertCount(1, $preview['discovery']['sample_rows']);
        $this->assertSame('Sample Diagnostic Supplier Product', $preview['discovery']['sample_rows'][0]['name']);
        $this->assertSame('SKIP', $preview['discovery']['sample_rows'][0]['sync_action']);

        $result
            ->assertSee('Sample rows that did not become CREATE')
            ->assertSee('Sample Diagnostic Supplier Product')
            ->assertSee('missing_required_data');
    }

    public function test_catalog_sync_preview_name_similarity_is_diagnostic_only_not_safe_update(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        Product::factory()->create([
            'name' => 'Satechi Duo Wireless Charger Power Bank Stand',
            'ean' => null,
            'mpn' => null,
            'supplier_sku' => null,
            'price' => 100,
            'quantity' => 5,
        ]);
        $this->supplierProduct($supplier, [
            'name' => 'Satechi Duo Wireless Charger Power Bank Stand',
            'supplier_sku' => 'SATECHI-SIMILARITY-ONLY',
            'ean' => null,
            'mpn' => null,
        ]);

        $preview = Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame(0, $preview['summary']['update_rows']);
        $this->assertSame(1, $preview['summary']['conflict_rows']);
        $this->assertSame(1, $preview['discovery']['match_type_summary']['name_similarity_only']);
        $this->assertSame(1, $preview['discovery']['unmatched_not_create_reasons']['conflict']);
        $this->assertSame(1, $preview['discovery']['skip_reason_summary']['conflict']);
    }

    public function test_catalog_sync_preview_create_candidate_diagnostics_are_read_only(): void
    {
        $this->actingAsSupplierManager();

        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Read Only Diagnostics Product',
            'supplier_sku' => null,
            'ean' => null,
            'mpn' => null,
        ]);

        $beforeProductCount = Product::query()->count();
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['name', 'ean', 'mpn', 'supplier_sku', 'price', 'quantity', 'status', 'product_id']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('filters.supplier_id', $supplier->id)
            ->set('filters.discovery_mode', 'create_candidates')
            ->instance()
            ->queryOnlySupplierProducts();

        $this->assertSame($beforeProductCount, Product::query()->count());
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(['name', 'ean', 'mpn', 'supplier_sku', 'price', 'quantity', 'status', 'product_id']));
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
