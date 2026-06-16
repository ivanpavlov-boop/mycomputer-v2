<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Products\CatalogSyncPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
