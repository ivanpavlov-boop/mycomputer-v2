<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_margin_overrides_category_supplier_and_global_rules(): void
    {
        $supplier = $this->supplier();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->pricingRule(PricingRule::SCOPE_GLOBAL, 5);
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 10, ['supplier_id' => $supplier->id]);
        $this->pricingRule(PricingRule::SCOPE_CATEGORY, 12, ['category_id' => $category->id]);
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
        $this->pricingRule(PricingRule::SCOPE_SUPPLIER, 15, ['supplier_id' => $supplier->id]);

        $result = app(PricingEngine::class)->calculateForSupplierProduct(
            $this->supplierProduct($supplier, ['price' => 100]),
        );

        $this->assertSame(PricingRule::SCOPE_SUPPLIER, $result['rule_scope']);
        $this->assertSame(115.0, $result['final_selling_price']);
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
}
