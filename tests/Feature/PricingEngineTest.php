<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductDiscountRule;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_margin_overrides_category_supplier_and_global_rules(): void
    {
        $supplier = $this->supplier();
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->pricingRule(PricingRule::SCOPE_GLOBAL, 5);
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 10, ['supplier_id' => $supplier->id]);
        $this->pricingRule(PricingRule::SCOPE_CATEGORY, 12, ['category_id' => $category->id]);
        $this->pricingRule(PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER, 30, [
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'supplier_id' => $supplier->id,
        ]);
        $this->pricingRule(PricingRule::SCOPE_PRODUCT, 20, ['product_id' => $product->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
            $product,
            $category,
        );

        $this->assertSame(PricingRule::SCOPE_PRODUCT, $result['rule_scope']);
        $this->assertSame(120.0, $result['final_selling_price']);
    }

    public function test_category_margin_overrides_supplier_rule(): void
    {
        $supplier = $this->supplier();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 10, ['supplier_id' => $supplier->id]);
        $this->pricingRule(PricingRule::SCOPE_CATEGORY, 12, ['category_id' => $category->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
            $product,
            $category,
        );

        $this->assertSame(PricingRule::SCOPE_CATEGORY, $result['rule_scope']);
        $this->assertSame(112.0, $result['final_selling_price']);
    }

    public function test_supplier_margin_overrides_global_rule(): void
    {
        $supplier = $this->supplier();

        $this->pricingRule(PricingRule::SCOPE_GLOBAL, 5);
        $this->pricingRule(PricingRule::SCOPE_PRICE_RANGE, 30, [
            'price_min' => 50,
            'price_max' => 150,
        ]);
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 15, ['supplier_id' => $supplier->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(PricingRule::SCOPE_SUPPLIER, $result['rule_scope']);
        $this->assertSame(115.0, $result['final_selling_price']);
    }

    public function test_category_brand_rule_overrides_inherited_category_rule_only_for_matching_brand(): void
    {
        $supplier = $this->supplier();
        $videoCards = Category::factory()->create(['name' => 'Video Cards', 'slug' => 'video-cards']);
        $rtxCards = Category::factory()->create([
            'parent_id' => $videoCards->id,
            'name' => 'RTX Video Cards',
            'slug' => 'rtx-video-cards',
        ]);
        $asus = Brand::factory()->create(['name' => 'ASUS', 'slug' => 'asus']);
        $msi = Brand::factory()->create(['name' => 'MSI', 'slug' => 'msi']);
        $asusProduct = Product::factory()->create([
            'brand_id' => $asus->id,
            'category_id' => $rtxCards->id,
            'supplier_id' => $supplier->id,
        ]);
        $msiProduct = Product::factory()->create([
            'brand_id' => $msi->id,
            'category_id' => $rtxCards->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->pricingRule(PricingRule::SCOPE_CATEGORY, 12, ['category_id' => $videoCards->id]);
        $this->pricingRule(PricingRule::SCOPE_CATEGORY_BRAND, 16, [
            'category_id' => $videoCards->id,
            'brand_id' => $asus->id,
        ]);

        $asusResult = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
            $asusProduct,
            $rtxCards,
        );
        $msiResult = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
            $msiProduct,
            $rtxCards,
        );

        $this->assertSame(PricingRule::SCOPE_CATEGORY_BRAND, $asusResult['rule_scope']);
        $this->assertSame(116.0, $asusResult['final_selling_price']);
        $this->assertSame(PricingRule::SCOPE_CATEGORY, $msiResult['rule_scope']);
        $this->assertSame(112.0, $msiResult['final_selling_price']);
    }

    public function test_category_brand_supplier_rule_has_highest_combined_scope_priority(): void
    {
        $supplier = $this->supplier();
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->pricingRule(PricingRule::SCOPE_CATEGORY_SUPPLIER, 14, [
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
        ]);
        $this->pricingRule(PricingRule::SCOPE_CATEGORY_BRAND, 16, [
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);
        $this->pricingRule(PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER, 18, [
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'supplier_id' => $supplier->id,
        ]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
            $product,
            $category,
        );

        $this->assertSame(PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER, $result['rule_scope']);
        $this->assertSame(118.0, $result['final_selling_price']);
    }

    public function test_brand_rule_overrides_supplier_and_price_range_rules(): void
    {
        $supplier = $this->supplier();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->pricingRule(PricingRule::SCOPE_PRICE_RANGE, 30, [
            'price_min' => 50,
            'price_max' => 150,
        ]);
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 10, ['supplier_id' => $supplier->id]);
        $this->pricingRule(PricingRule::SCOPE_BRAND, 17, ['brand_id' => $brand->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
            $product,
        );

        $this->assertSame(PricingRule::SCOPE_BRAND, $result['rule_scope']);
        $this->assertSame(117.0, $result['final_selling_price']);
    }

    public function test_price_range_rule_overrides_global_default(): void
    {
        $supplier = $this->supplier();

        $this->pricingRule(PricingRule::SCOPE_GLOBAL, 5);
        $this->pricingRule(PricingRule::SCOPE_PRICE_RANGE, 9, [
            'price_min' => 50,
            'price_max' => 150,
        ]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(PricingRule::SCOPE_PRICE_RANGE, $result['rule_scope']);
        $this->assertSame(109.0, $result['final_selling_price']);
    }

    public function test_msrp_strategies_are_applied(): void
    {
        $cases = [
            PricingRule::MSRP_MARGIN_ONLY => 120.0,
            PricingRule::MSRP_RECOMMENDED_ONLY => 130.0,
            PricingRule::MSRP_RECOMMENDED_MIN_MARGIN => 125.0,
            PricingRule::MSRP_HIGHER_OF_MARGIN_OR_RECOMMENDED => 130.0,
            PricingRule::MSRP_LOWER_OF_MARGIN_OR_RECOMMENDED => 120.0,
        ];

        foreach ($cases as $strategy => $expectedPrice) {
            PricingRule::query()->delete();

            $supplier = $this->supplier(['msrp_strategy' => $strategy]);
            $recommendedPrice = $strategy === PricingRule::MSRP_RECOMMENDED_MIN_MARGIN ? 110 : 130;

            $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, [
                'supplier_id' => $supplier->id,
                'minimum_margin' => $strategy === PricingRule::MSRP_RECOMMENDED_MIN_MARGIN ? 25 : null,
            ]);

            $result = app(PricingEngine::class)->calculateForSupplierProduct(
                $this->supplierProduct($supplier, [
                    'price' => 100,
                    'recommended_price' => $recommendedPrice,
                ]),
            );

            $this->assertSame($expectedPrice, $result['final_selling_price'], $strategy);
        }
    }

    public function test_minimum_margin_and_minimum_final_price_are_enforced(): void
    {
        $supplier = $this->supplier();
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 10, [
            'supplier_id' => $supplier->id,
            'minimum_margin' => 25,
            'minimum_final_price' => 130,
        ]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(130.0, $result['final_selling_price']);
    }

    public function test_vat_included_supplier_normalizes_purchase_cost_without_changing_raw_price(): void
    {
        $supplier = $this->supplier([
            'vat_mode' => 'price_includes_vat',
            'vat_rate' => 20,
        ]);
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 120]),
        );

        $this->assertSame(120.0, $result['supplier_price_raw']);
        $this->assertSame(120.0, $result['purchase_price']);
        $this->assertSame(100.0, $result['normalized_purchase_cost']);
        $this->assertSame(120.0, $result['final_selling_price']);
    }

    public function test_vat_excluded_supplier_uses_raw_price_as_normalized_cost(): void
    {
        $supplier = $this->supplier([
            'vat_mode' => 'price_excludes_vat',
            'vat_rate' => 20,
        ]);
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(100.0, $result['normalized_purchase_cost']);
        $this->assertSame(120.0, $result['final_selling_price']);
    }

    public function test_reverse_charge_supplier_uses_raw_price_as_normalized_cost(): void
    {
        $supplier = $this->supplier([
            'vat_mode' => 'reverse_charge_eu',
            'vat_rate' => 20,
        ]);
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame('EUR', $result['currency']);
        $this->assertSame(100.0, $result['normalized_purchase_cost']);
        $this->assertSame(120.0, $result['final_selling_price']);
    }

    public function test_fixed_margin_and_rounding_rules_are_supported_for_category_pricing(): void
    {
        $supplier = $this->supplier();
        $category = Category::factory()->create();

        $this->pricingRule(PricingRule::SCOPE_CATEGORY, 15, [
            'category_id' => $category->id,
            'margin_type' => PricingRule::MARGIN_FIXED,
            'rounding_rule' => PricingRule::ROUND_UP_0_99,
        ]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
            category: $category,
        );

        $this->assertSame(PricingRule::SCOPE_CATEGORY, $result['rule_scope']);
        $this->assertSame(115.99, $result['final_selling_price']);
    }

    public function test_supplier_product_regular_price_is_calculated_without_sale_price_by_default(): void
    {
        $supplier = $this->supplier();
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame('EUR', $result['currency']);
        $this->assertSame(120.0, $result['regular_price']);
        $this->assertSame(120.0, $result['final_selling_price']);
        $this->assertNull($result['sale_price']);
        $this->assertNull($result['sale_price_source']);
    }

    public function test_supplier_product_sale_price_is_applied_only_when_active_discount_rule_exists(): void
    {
        $supplier = $this->supplier();
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);
        $this->discountRule(ProductDiscountRule::SCOPE_SUPPLIER, ProductDiscountRule::TYPE_PERCENTAGE, 10, [
            'supplier_id' => $supplier->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(120.0, $result['regular_price']);
        $this->assertSame(108.0, $result['sale_price']);
        $this->assertSame(Product::SALE_PRICE_SOURCE_PROMOTION_RULE, $result['sale_price_source']);
    }

    public function test_fixed_sale_price_and_fixed_amount_discounts_are_supported(): void
    {
        $supplier = $this->supplier();
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);
        $this->discountRule(ProductDiscountRule::SCOPE_SUPPLIER, ProductDiscountRule::TYPE_FIXED_PRICE, 99, [
            'supplier_id' => $supplier->id,
        ]);

        $fixedPrice = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        ProductDiscountRule::query()->delete();
        $this->discountRule(ProductDiscountRule::SCOPE_SUPPLIER, ProductDiscountRule::TYPE_FIXED_AMOUNT, 15, [
            'supplier_id' => $supplier->id,
        ]);

        $fixedAmount = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(99.0, $fixedPrice['sale_price']);
        $this->assertSame(105.0, $fixedAmount['sale_price']);
    }

    public function test_sale_price_cannot_be_higher_than_regular_price(): void
    {
        $supplier = $this->supplier();
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);
        $this->discountRule(ProductDiscountRule::SCOPE_SUPPLIER, ProductDiscountRule::TYPE_FIXED_PRICE, 150, [
            'supplier_id' => $supplier->id,
        ]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(120.0, $result['regular_price']);
        $this->assertNull($result['sale_price']);
    }

    public function test_expired_and_future_sale_prices_are_ignored(): void
    {
        $supplier = $this->supplier();
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 20, ['supplier_id' => $supplier->id]);
        $this->discountRule(ProductDiscountRule::SCOPE_SUPPLIER, ProductDiscountRule::TYPE_PERCENTAGE, 10, [
            'supplier_id' => $supplier->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);

        $expired = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        ProductDiscountRule::query()->delete();
        $this->discountRule(ProductDiscountRule::SCOPE_SUPPLIER, ProductDiscountRule::TYPE_PERCENTAGE, 10, [
            'supplier_id' => $supplier->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);

        $future = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertNull($expired['sale_price']);
        $this->assertNull($future['sale_price']);
    }

    public function test_product_active_sale_price_starts_in_future(): void
    {
        Carbon::setTestNow('2026-06-16 12:00:00');

        $product = Product::factory()->create([
            'regular_price' => 120,
            'price' => 120,
            'sale_price' => 99,
            'sale_price_starts_at' => now()->addDay(),
            'sale_price_ends_at' => now()->addWeek(),
        ]);

        $this->assertNull($product->activeSalePrice());
        $this->assertSame(120.0, $product->effectivePrice());

        Carbon::setTestNow();
    }

    public function test_product_active_sale_price_is_used_during_date_range(): void
    {
        Carbon::setTestNow('2026-06-16 12:00:00');

        $product = Product::factory()->create([
            'regular_price' => 120,
            'price' => 120,
            'sale_price' => 99,
            'sale_price_starts_at' => now()->subDay(),
            'sale_price_ends_at' => now()->addDay(),
        ]);

        $this->assertSame(99.0, $product->activeSalePrice());
        $this->assertSame(99.0, $product->effectivePrice());

        Carbon::setTestNow();
    }

    public function test_product_sale_price_expires_after_end_date_and_falls_back_to_regular_price(): void
    {
        Carbon::setTestNow('2026-06-16 12:00:00');

        $product = Product::factory()->create([
            'regular_price' => 120,
            'price' => 120,
            'sale_price' => 99,
            'sale_price_starts_at' => now()->subWeek(),
            'sale_price_ends_at' => now()->subMinute(),
        ]);

        $this->assertNull($product->activeSalePrice());
        $this->assertSame(120.0, $product->effectivePrice());

        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::factory()->create($overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => fake()->unique()->bothify('SUP-####??'),
            'ean' => fake()->ean13(),
            'mpn' => fake()->bothify('MPN-####??'),
            'name' => 'Pricing Test Product',
            'brand_name' => 'Test Brand',
            'category_name' => 'Test Category',
            'price' => 100,
            'supplier_price_raw' => 100,
            'quantity' => 5,
            'currency' => 'EUR',
            'raw_data' => ['source' => 'pricing-test'],
            'payload_hash' => fake()->unique()->sha1(),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pricingRule(string $scope, float $marginValue, array $overrides = []): PricingRule
    {
        return PricingRule::query()->create(array_merge([
            'name' => "{$scope} pricing rule",
            'scope_type' => $scope,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => $marginValue,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function discountRule(string $scope, string $discountType, float $discountValue, array $overrides = []): ProductDiscountRule
    {
        return ProductDiscountRule::query()->create(array_merge([
            'name' => "{$scope} discount rule",
            'scope_type' => $scope,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }
}
