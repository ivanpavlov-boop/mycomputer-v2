<?php

namespace App\Services\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

final class ProductCategoryBrandQualityService
{
    /** @var WeakMap<Product, ProductCategoryBrandQualityResult> */
    private WeakMap $results;

    public function __construct(
        private readonly ProductDataQualityScanner $scanner,
    ) {
        $this->results = new WeakMap;
    }

    public function evaluate(Product $product): ProductCategoryBrandQualityResult
    {
        return $this->results[$product] ??= $this->evaluateFresh($product);
    }

    public function applyStateQuery(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY => $this->scanner
                ->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_CATEGORY)
                ->whereNotNull('brand_id'),
            ProductCategoryBrandQualityResult::STATE_MISSING_BRAND => $this->scanner
                ->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_BRAND)
                ->whereNotNull('category_id'),
            ProductCategoryBrandQualityResult::STATE_MISSING_BOTH => $this->scanner
                ->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_CATEGORY)
                ->whereNull('brand_id'),
            ProductCategoryBrandQualityResult::STATE_COMPLETE => $query
                ->whereNotNull('category_id')
                ->whereNotNull('brand_id'),
            default => $query,
        };
    }

    /**
     * @return array{missing_category: int, missing_brand: int, missing_both: int, complete: int}
     */
    public function countsFor(Builder $query): array
    {
        $counts = $query
            ->selectRaw('SUM(CASE WHEN category_id IS NULL AND brand_id IS NOT NULL THEN 1 ELSE 0 END) AS missing_category')
            ->selectRaw('SUM(CASE WHEN category_id IS NOT NULL AND brand_id IS NULL THEN 1 ELSE 0 END) AS missing_brand')
            ->selectRaw('SUM(CASE WHEN category_id IS NULL AND brand_id IS NULL THEN 1 ELSE 0 END) AS missing_both')
            ->selectRaw('SUM(CASE WHEN category_id IS NOT NULL AND brand_id IS NOT NULL THEN 1 ELSE 0 END) AS complete')
            ->first();

        return [
            ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY => (int) ($counts?->missing_category ?? 0),
            ProductCategoryBrandQualityResult::STATE_MISSING_BRAND => (int) ($counts?->missing_brand ?? 0),
            ProductCategoryBrandQualityResult::STATE_MISSING_BOTH => (int) ($counts?->missing_both ?? 0),
            ProductCategoryBrandQualityResult::STATE_COMPLETE => (int) ($counts?->complete ?? 0),
        ];
    }

    private function evaluateFresh(Product $product): ProductCategoryBrandQualityResult
    {
        $missingCategory = $this->scanner->productHasIssue($product, ProductDataQualityScanner::ISSUE_MISSING_CATEGORY);
        $missingBrand = $this->scanner->productHasIssue($product, ProductDataQualityScanner::ISSUE_MISSING_BRAND);
        $category = $missingCategory ? null : $this->resolveCategory($product);
        $brand = $missingBrand ? null : $this->resolveBrand($product);
        $categoryArchived = (bool) $category?->trashed();
        $brandArchived = (bool) $brand?->trashed();
        $categoryInactive = $category !== null && ! (bool) $category->is_active;
        $brandInactive = $brand !== null && ! (bool) $brand->is_active;
        $warnings = array_values(array_filter([
            match (true) {
                $categoryArchived => 'Архивирана категория',
                $categoryInactive => 'Неактивна категория',
                default => null,
            },
            match (true) {
                $brandArchived => 'Архивирана марка',
                $brandInactive => 'Неактивна марка',
                default => null,
            },
        ]));

        return new ProductCategoryBrandQualityResult(
            state: match (true) {
                $missingCategory && $missingBrand => ProductCategoryBrandQualityResult::STATE_MISSING_BOTH,
                $missingCategory => ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY,
                $missingBrand => ProductCategoryBrandQualityResult::STATE_MISSING_BRAND,
                default => ProductCategoryBrandQualityResult::STATE_COMPLETE,
            },
            categoryLabel: $category?->name,
            categoryPath: $this->categoryPath($category),
            brandLabel: $brand?->name,
            categoryInactive: $categoryInactive,
            categoryArchived: $categoryArchived,
            brandInactive: $brandInactive,
            brandArchived: $brandArchived,
            warnings: $warnings,
        );
    }

    private function resolveCategory(Product $product): ?Category
    {
        if ($product->relationLoaded('category') && $product->category !== null) {
            return $product->category;
        }

        return Category::query()
            ->withTrashed()
            ->with(['parent' => fn ($query) => $query->withTrashed()])
            ->find($product->category_id);
    }

    private function resolveBrand(Product $product): ?Brand
    {
        if ($product->relationLoaded('brand') && $product->brand !== null) {
            return $product->brand;
        }

        return Brand::query()->withTrashed()->find($product->brand_id);
    }

    private function categoryPath(?Category $category): ?string
    {
        if ($category === null) {
            return null;
        }

        return collect([$category->parent?->name, $category->name])
            ->filter(fn (?string $label): bool => filled($label))
            ->implode(' › ');
    }
}
