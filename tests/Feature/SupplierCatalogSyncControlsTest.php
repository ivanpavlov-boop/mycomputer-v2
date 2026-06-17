<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Models\Supplier;
use App\Models\SupplierExclusionRule;
use App\Models\SupplierProduct;
use App\Services\Products\CatalogSyncPreviewService;
use App\Services\Products\ProductSyncService;
use App\Services\Products\SupplierOfferSelectionService;
use App\Services\Suppliers\SupplierExclusionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCatalogSyncControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_exclusion_rules_match_supported_scopes(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $category = Category::factory()->create(['name' => 'Mice', 'slug' => 'mice']);
        $brand = Brand::factory()->create(['name' => 'Satechi', 'slug' => 'satechi']);

        $cases = [
            ['rule' => ['supplier_id' => $supplier->id], 'product' => []],
            ['rule' => ['category_id' => $category->id], 'product' => ['category_name' => 'Accessories > Mice']],
            ['rule' => ['brand_id' => $brand->id], 'product' => ['brand_name' => 'Satechi']],
            ['rule' => ['supplier_id' => $supplier->id, 'category_id' => $category->id], 'product' => ['category_name' => 'Mice']],
            ['rule' => ['sku' => 'APC-SKU-1'], 'product' => ['supplier_sku' => 'APC-SKU-1']],
            ['rule' => ['ean' => '1234567890123'], 'product' => ['ean' => '1234567890123']],
            ['rule' => ['exclude_zero_stock' => true], 'product' => ['quantity' => 0]],
            ['rule' => ['exclude_eol' => true], 'product' => ['category_name' => 'EOL Products']],
            ['rule' => ['exclude_missing_ean' => true], 'product' => ['ean' => null]],
        ];

        foreach ($cases as $index => $case) {
            SupplierExclusionRule::query()->delete();
            SupplierExclusionRule::query()->create(array_merge([
                'name' => 'Rule '.$index,
                'is_active' => true,
                'priority' => 10,
            ], $case['rule']));

            $supplierProduct = $this->supplierProduct($supplier, $case['product']);

            $this->assertTrue(app(SupplierExclusionService::class)->isExcluded($supplierProduct), 'Case '.$index.' did not match.');
        }
    }

    public function test_excluded_products_show_skip_and_are_not_synced(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'EXCLUDED-SKU',
            'category_name' => 'Accessories > Mice',
        ]);
        $category = Category::factory()->create(['name' => 'Mice', 'slug' => 'mice']);
        SupplierExclusionRule::query()->create([
            'name' => 'APCOM Mice',
            'is_active' => true,
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'priority' => 10,
        ]);

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);
        $log = app(ProductSyncService::class)->sync($supplierProduct);

        $this->assertSame('skip', $row['target_catalog_action']);
        $this->assertTrue($row['excluded']);
        $this->assertSame('APCOM + Category Mice', $row['exclusion_rule']);
        $this->assertStringContainsString('Excluded by rule', $row['reason']);
        $this->assertSame('skipped', $log->status);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_duplicate_matching_uses_ean_and_mpn_brand_but_not_name_similarity(): void
    {
        $supplier = Supplier::factory()->create();
        $brand = Brand::factory()->create(['name' => 'ASUS', 'slug' => 'asus']);
        $existing = Product::factory()->create([
            'brand_id' => $brand->id,
            'ean' => '1111111111111',
            'mpn' => 'MPN-ONE',
            'name' => 'Very Similar Name',
        ]);

        $eanRow = app(CatalogSyncPreviewService::class)->previewSupplierProduct($this->supplierProduct($supplier, [
            'ean' => '1111111111111',
            'mpn' => 'OTHER-MPN',
        ]));
        $mpnBrandRow = app(CatalogSyncPreviewService::class)->previewSupplierProduct($this->supplierProduct($supplier, [
            'ean' => null,
            'mpn' => 'MPN-ONE',
            'brand_name' => 'ASUS',
        ]));
        $nameOnlyRow = app(CatalogSyncPreviewService::class)->previewSupplierProduct($this->supplierProduct($supplier, [
            'ean' => '2222222222222',
            'mpn' => 'DIFFERENT-MPN',
            'name' => 'Very Similar Name',
        ]));

        $this->assertSame($existing->id, $eanRow['target_product_id']);
        $this->assertSame('EAN', $eanRow['matched_by_display']);
        $this->assertSame($existing->id, $mpnBrandRow['target_product_id']);
        $this->assertSame('MPN + Brand', $mpnBrandRow['matched_by_display']);
        $this->assertSame('create', $nameOnlyRow['target_catalog_action']);
        $this->assertSame('None', $nameOnlyRow['matched_by_display']);
    }

    public function test_supplier_sku_matches_only_within_same_supplier(): void
    {
        $supplierA = Supplier::factory()->create();
        $supplierB = Supplier::factory()->create();
        Product::factory()->create([
            'supplier_id' => $supplierA->id,
            'supplier_sku' => 'SHARED-SKU',
            'sku' => 'CAT-A',
            'ean' => null,
            'mpn' => null,
        ]);

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($this->supplierProduct($supplierB, [
            'supplier_sku' => 'SHARED-SKU',
            'ean' => null,
            'mpn' => null,
        ]));

        $this->assertSame('create', $row['target_catalog_action']);
        $this->assertSame('None', $row['matched_by_display']);
    }

    public function test_multiple_catalog_matches_create_conflict(): void
    {
        $supplier = Supplier::factory()->create();
        Product::factory()->count(2)->create(['ean' => '9999999999999']);

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($this->supplierProduct($supplier, [
            'ean' => '9999999999999',
        ]));

        $this->assertSame('conflict', $row['target_catalog_action']);
    }

    public function test_product_sync_duplicate_detection_matches_preview_for_existing_supplier_rows(): void
    {
        $supplier = Supplier::factory()->create();
        $existingRow = $this->supplierProduct($supplier, [
            'ean' => '8888888888888',
            'status' => 'synced',
        ]);
        $currentRow = $this->supplierProduct($supplier, [
            'ean' => '8888888888888',
            'supplier_sku' => 'CURRENT-DUPLICATE',
        ]);

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($currentRow);
        $log = app(ProductSyncService::class)->sync($currentRow);

        $this->assertSame('conflict', $row['target_catalog_action']);
        $this->assertContains('duplicate_supplier_identifiers', $row['conflict_reasons']);
        $this->assertSame('duplicate', $log->status);
        $this->assertSame('synced', $existingRow->refresh()->status);
    }

    public function test_same_ean_maps_multiple_suppliers_to_same_catalog_product_with_multiple_offers(): void
    {
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM']);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS']);
        $first = $this->supplierProduct($apcom, ['ean' => '1231231231231', 'supplier_sku' => 'APC-1']);
        $second = $this->supplierProduct($asbis, ['ean' => '1231231231231', 'supplier_sku' => 'ASB-1']);

        app(ProductSyncService::class)->sync($first);
        app(ProductSyncService::class)->sync($second);

        $this->assertSame(1, Product::query()->where('ean', '1231231231231')->count());
        $this->assertSame(2, ProductSupplierOffer::query()->count());
    }

    public function test_offer_selection_rules_choose_lowest_eligible_supplier_offer(): void
    {
        $product = Product::factory()->create();
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM', 'priority' => 10]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'priority' => 1]);
        $also = Supplier::factory()->create(['company_name' => 'ALSO', 'priority' => 20]);

        $apcomProduct = $this->supplierProduct($apcom, ['price' => 18, 'quantity' => 10]);
        $asbisProduct = $this->supplierProduct($asbis, ['price' => 17, 'quantity' => 0]);
        $alsoProduct = $this->supplierProduct($also, ['price' => 19, 'quantity' => 20]);

        $apcomOffer = $this->offer($product, $apcomProduct);
        $this->offer($product, $asbisProduct);
        $this->offer($product, $alsoProduct);

        $selection = app(SupplierOfferSelectionService::class)->select($product);

        $this->assertSame($apcomOffer->id, $selection['offer']->id);
        $this->assertSame('Lowest available in-stock supplier.', $selection['reason']);
    }

    public function test_offer_selection_uses_normalized_purchase_cost_instead_of_raw_supplier_price(): void
    {
        $product = Product::factory()->create();
        $vatIncludedSupplier = Supplier::factory()->create([
            'company_name' => 'VAT Included Supplier',
            'priority' => 10,
            'vat_mode' => 'price_includes_vat',
            'vat_rate' => 20,
        ]);
        $vatExcludedSupplier = Supplier::factory()->create([
            'company_name' => 'VAT Excluded Supplier',
            'priority' => 10,
            'vat_mode' => 'price_excludes_vat',
            'vat_rate' => 20,
        ]);

        $includedProduct = $this->supplierProduct($vatIncludedSupplier, ['ean' => '5555555555555', 'price' => 120, 'quantity' => 5]);
        $excludedProduct = $this->supplierProduct($vatExcludedSupplier, ['ean' => '5555555555555', 'price' => 110, 'quantity' => 5]);

        $includedOffer = $this->offer($product, $includedProduct);
        $this->offer($product, $excludedProduct);

        $selection = app(SupplierOfferSelectionService::class)->select($product);
        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($includedProduct);
        $includedCandidate = collect($selection['candidates'])
            ->firstWhere('supplier_id', $vatIncludedSupplier->id);

        $this->assertSame($includedOffer->id, $selection['offer']->id);
        $this->assertSame(100.0, $includedCandidate['normalized_purchase_cost']);
        $this->assertSame('VAT Included Supplier', $row['winning_offer_supplier']);
    }

    public function test_offer_selection_ignores_excluded_and_inactive_offers_and_uses_tie_breakers(): void
    {
        $product = Product::factory()->create();
        $excludedSupplier = Supplier::factory()->create(['priority' => 1]);
        $inactiveSupplier = Supplier::factory()->create(['priority' => 1, 'status' => 'inactive']);
        $prioritySupplier = Supplier::factory()->create(['company_name' => 'Priority', 'priority' => 5]);
        $preferredSupplier = Supplier::factory()->create(['company_name' => 'Preferred', 'priority' => 5]);

        $excludedProduct = $this->supplierProduct($excludedSupplier, ['supplier_sku' => 'EXCLUDE-ME', 'price' => 10, 'quantity' => 5]);
        SupplierExclusionRule::query()->create([
            'name' => 'Exclude SKU',
            'is_active' => true,
            'sku' => 'EXCLUDE-ME',
            'priority' => 1,
        ]);

        $inactiveProduct = $this->supplierProduct($inactiveSupplier, ['price' => 9, 'quantity' => 5]);
        $priorityProduct = $this->supplierProduct($prioritySupplier, ['price' => 20, 'quantity' => 5]);
        $preferredProduct = $this->supplierProduct($preferredSupplier, ['price' => 20, 'quantity' => 5]);

        $this->offer($product, $excludedProduct);
        $this->offer($product, $inactiveProduct);
        $this->offer($product, $priorityProduct);
        $preferredOffer = $this->offer($product, $preferredProduct, ['is_preferred' => true]);

        $selection = app(SupplierOfferSelectionService::class)->select($product);

        $this->assertSame($preferredOffer->id, $selection['offer']->id);
    }

    public function test_no_stock_anywhere_marks_product_out_of_stock_during_sync(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier, ['quantity' => 0]);

        app(ProductSyncService::class)->sync($supplierProduct);

        $product = Product::query()->firstOrFail();

        $this->assertSame(0, $product->quantity);
        $this->assertSame('out_of_stock', $product->stock_status);
    }

    private function offer(Product $product, SupplierProduct $supplierProduct, array $overrides = []): ProductSupplierOffer
    {
        return ProductSupplierOffer::query()->create(array_merge([
            'product_id' => $product->id,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'price' => $supplierProduct->price,
            'quantity' => $supplierProduct->quantity ?? 0,
            'currency' => 'EUR',
            'supplier_priority' => $supplierProduct->supplier?->priority ?? 100,
            'is_preferred' => false,
            'last_seen_at' => now(),
        ], $overrides));
    }

    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => fake()->unique()->bothify('SUP-####??'),
            'ean' => fake()->unique()->numerify('#############'),
            'mpn' => fake()->unique()->bothify('MPN-####??'),
            'name' => 'Supplier Control Test Product',
            'brand_name' => 'Preview Brand',
            'category_name' => 'Preview Category',
            'price' => 100,
            'quantity' => 5,
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => fake()->unique()->sha1(),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }
}
