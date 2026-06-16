<?php

namespace App\Services\Pricing;

use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;

class PricingEngine
{
    /**
     * @return array<string, mixed>
     */
    public function calculateForSupplierProduct(SupplierProduct $supplierProduct, ?Product $product = null, ?Category $category = null): array
    {
        $supplierProduct->loadMissing('supplier');

        $supplier = $supplierProduct->supplier;
        $category ??= $product?->category;
        $rawPrice = (float) ($supplierProduct->price ?? $supplierProduct->supplier_price_raw ?? 0);
        $recommendedPrice = $supplierProduct->recommended_price !== null ? (float) $supplierProduct->recommended_price : null;
        $normalizedCost = $this->normalizedPurchaseCost($rawPrice, $supplier);
        $rule = $this->matchingRule($product, $category, $supplier, $normalizedCost);
        $marginPrice = $this->marginPrice($normalizedCost, $rule);
        $finalPrice = $this->applyRecommendedPriceStrategy($marginPrice, $normalizedCost, $recommendedPrice, $rule, $supplier);
        $finalPrice = $this->applyMinimums($finalPrice, $normalizedCost, $rule);
        $finalPrice = $this->roundPrice($finalPrice, $rule?->rounding_rule ?? PricingRule::ROUND_NONE);

        return [
            'currency' => 'EUR',
            'rule_id' => $rule?->id,
            'rule_scope' => $rule?->scope_type,
            'supplier_price_raw' => round($rawPrice, 2),
            'purchase_price' => round($rawPrice, 2),
            'normalized_purchase_cost' => round($normalizedCost, 2),
            'recommended_price' => $recommendedPrice !== null ? round($recommendedPrice, 2) : null,
            'margin_price' => round($marginPrice, 2),
            'final_selling_price' => round($finalPrice, 2),
            'msrp_strategy' => $rule?->msrp_strategy ?: ($supplier?->msrp_strategy ?: PricingRule::MSRP_MARGIN_ONLY),
            'vat_mode' => $supplier?->vat_mode ?: 'price_excludes_vat',
            'vat_rate' => $supplier?->vat_rate !== null ? (float) $supplier->vat_rate : null,
        ];
    }

    public function matchingRule(?Product $product = null, ?Category $category = null, ?Supplier $supplier = null, ?float $normalizedCost = null): ?PricingRule
    {
        $brandId = $product?->brand_id;

        if ($product?->id) {
            $rule = PricingRule::query()
                ->active()
                ->where('scope_type', PricingRule::SCOPE_PRODUCT)
                ->where('product_id', $product->id)
                ->orderBy('sort_order')
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        if ($brandId && $supplier?->id) {
            $rule = $this->matchingCategoryRule(
                PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER,
                $category,
                brandId: $brandId,
                supplierId: $supplier->id,
            );

            if ($rule) {
                return $rule;
            }
        }

        if ($brandId) {
            $rule = $this->matchingCategoryRule(
                PricingRule::SCOPE_CATEGORY_BRAND,
                $category,
                brandId: $brandId,
            );

            if ($rule) {
                return $rule;
            }
        }

        if ($supplier?->id) {
            $rule = $this->matchingCategoryRule(
                PricingRule::SCOPE_CATEGORY_SUPPLIER,
                $category,
                supplierId: $supplier->id,
            );

            if ($rule) {
                return $rule;
            }
        }

        foreach ($this->categoryHierarchy($category) as $categoryId) {
            $rule = PricingRule::query()
                ->active()
                ->where('scope_type', PricingRule::SCOPE_CATEGORY)
                ->where('category_id', $categoryId)
                ->orderBy('sort_order')
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        if ($brandId) {
            $rule = PricingRule::query()
                ->active()
                ->where('scope_type', PricingRule::SCOPE_BRAND)
                ->where('brand_id', $brandId)
                ->orderBy('sort_order')
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        if ($supplier?->id) {
            $rule = PricingRule::query()
                ->active()
                ->where('scope_type', PricingRule::SCOPE_SUPPLIER)
                ->where('supplier_id', $supplier->id)
                ->orderBy('sort_order')
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        if ($normalizedCost !== null) {
            $rule = PricingRule::query()
                ->active()
                ->where('scope_type', PricingRule::SCOPE_PRICE_RANGE)
                ->where(function ($query) use ($normalizedCost): void {
                    $query->whereNull('price_min')->orWhere('price_min', '<=', $normalizedCost);
                })
                ->where(function ($query) use ($normalizedCost): void {
                    $query->whereNull('price_max')->orWhere('price_max', '>=', $normalizedCost);
                })
                ->orderBy('sort_order')
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        return PricingRule::query()
            ->active()
            ->where('scope_type', PricingRule::SCOPE_GLOBAL)
            ->orderBy('sort_order')
            ->first();
    }

    protected function matchingCategoryRule(string $scope, ?Category $category, ?int $brandId = null, ?int $supplierId = null): ?PricingRule
    {
        foreach ($this->categoryHierarchy($category) as $categoryId) {
            $query = PricingRule::query()
                ->active()
                ->where('scope_type', $scope)
                ->where('category_id', $categoryId);

            if ($brandId !== null) {
                $query->where('brand_id', $brandId);
            }

            if ($supplierId !== null) {
                $query->where('supplier_id', $supplierId);
            }

            $rule = $query->orderBy('sort_order')->first();

            if ($rule) {
                return $rule;
            }
        }

        return null;
    }

    protected function normalizedPurchaseCost(float $rawPrice, ?Supplier $supplier): float
    {
        if (($supplier?->vat_mode) !== 'price_includes_vat') {
            return $rawPrice;
        }

        $vatRate = $supplier->vat_rate !== null ? (float) $supplier->vat_rate : 0.0;

        if ($vatRate <= 0) {
            return $rawPrice;
        }

        return $rawPrice / (1 + ($vatRate / 100));
    }

    protected function marginPrice(float $cost, ?PricingRule $rule): float
    {
        if (! $rule) {
            return $cost;
        }

        if ($rule->margin_type === PricingRule::MARGIN_FIXED) {
            return $cost + (float) $rule->margin_value;
        }

        return $cost * (1 + ((float) $rule->margin_value / 100));
    }

    protected function applyRecommendedPriceStrategy(float $marginPrice, float $cost, ?float $recommendedPrice, ?PricingRule $rule, ?Supplier $supplier): float
    {
        if ($recommendedPrice === null) {
            return $marginPrice;
        }

        $strategy = $rule?->msrp_strategy ?: ($supplier?->msrp_strategy ?: PricingRule::MSRP_MARGIN_ONLY);

        return match ($strategy) {
            PricingRule::MSRP_RECOMMENDED_ONLY => $recommendedPrice,
            PricingRule::MSRP_RECOMMENDED_MIN_MARGIN => max($recommendedPrice, $cost + (float) ($rule?->minimum_margin ?? 0)),
            PricingRule::MSRP_HIGHER_OF_MARGIN_OR_RECOMMENDED => max($marginPrice, $recommendedPrice),
            PricingRule::MSRP_LOWER_OF_MARGIN_OR_RECOMMENDED => min($marginPrice, $recommendedPrice),
            default => $marginPrice,
        };
    }

    protected function applyMinimums(float $price, float $cost, ?PricingRule $rule): float
    {
        if (! $rule) {
            return $price;
        }

        if ($rule->minimum_margin !== null) {
            $price = max($price, $cost + (float) $rule->minimum_margin);
        }

        if ($rule->minimum_final_price !== null) {
            $price = max($price, (float) $rule->minimum_final_price);
        }

        return $price;
    }

    protected function roundPrice(float $price, string $roundingRule): float
    {
        return match ($roundingRule) {
            PricingRule::ROUND_NEAREST_0_05 => round($price / 0.05) * 0.05,
            PricingRule::ROUND_NEAREST_0_10 => round($price / 0.10) * 0.10,
            PricingRule::ROUND_UP_0_99 => floor($price) + 0.99,
            default => round($price, 2),
        };
    }

    /**
     * @return array<int, int>
     */
    protected function categoryHierarchy(?Category $category): array
    {
        $ids = [];

        while ($category) {
            $ids[] = $category->id;
            $category = $category->parent;
        }

        return $ids;
    }
}
