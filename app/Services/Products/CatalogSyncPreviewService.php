<?php

namespace App\Services\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\SupplierProduct;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Pricing\PricingEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogSyncPreviewService
{
    public function __construct(
        private readonly PricingEngine $pricingEngine,
        private readonly AvailabilityStatusMapper $availabilityMapper,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int|float>, rows: array<int, array<string, mixed>>}
     */
    public function preview(array $filters = [], int|string $limit = 50): array
    {
        $rows = $this->supplierProductsQuery($filters)
            ->limit($this->normalizeLimit($limit))
            ->get()
            ->map(fn (SupplierProduct $supplierProduct): array => $this->previewSupplierProduct($supplierProduct))
            ->filter(fn (array $row): bool => $this->matchesActionFilter($row, $filters['action'] ?? null))
            ->filter(fn (array $row): bool => $this->matchesQuickFilter($row, $filters['quick_filter'] ?? null))
            ->pipe(fn (Collection $rows): Collection => $this->sortRows($rows, $filters))
            ->values();

        return [
            'summary' => $this->summary($rows),
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function fullSummary(array $filters = []): array
    {
        $rows = $this->supplierProductsQuery($filters)
            ->get()
            ->map(fn (SupplierProduct $supplierProduct): array => $this->previewSupplierProduct($supplierProduct))
            ->filter(fn (array $row): bool => $this->matchesActionFilter($row, $filters['action'] ?? null))
            ->filter(fn (array $row): bool => $this->matchesQuickFilter($row, $filters['quick_filter'] ?? null))
            ->pipe(fn (Collection $rows): Collection => $this->sortRows($rows, $filters))
            ->values();

        return $this->summary($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewSupplierProduct(SupplierProduct $supplierProduct): array
    {
        $supplierProduct->loadMissing('supplier');

        $matches = $this->matchCatalogProducts($supplierProduct);
        $duplicateSupplierRows = $this->hasDuplicateSupplierRows($supplierProduct);
        $action = $this->action($supplierProduct, $matches, $duplicateSupplierRows);
        $targetProduct = $matches['products']->first();
        $brand = $this->findExistingBrand($supplierProduct->brand_name);
        $category = $this->findExistingCategory($supplierProduct->category_name);
        $availability = $this->availabilityMapper->mapWithFallback(
            'supplier',
            $supplierProduct->supplier?->company_name,
            $supplierProduct->external_availability_status,
            $supplierProduct->quantity,
        );
        $pricing = $this->pricingPreview($supplierProduct, $targetProduct, $brand, $category, $action);
        $imageCount = count($this->extractImageUrls($supplierProduct->raw_data ?? []));
        $profitAmount = $this->profitAmount($supplierProduct->price, $pricing['final_selling_price']);
        $marginPercent = $this->marginPercent($supplierProduct->price, $profitAmount);

        return [
            'supplier_product_id' => $supplierProduct->id,
            'supplier_name' => $supplierProduct->supplier?->company_name,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
            'product_name' => $supplierProduct->name,
            'brand' => $supplierProduct->brand_name,
            'category' => $supplierProduct->category_name,
            'raw_category_data' => $supplierProduct->category_name,
            'normalized_category' => $this->normalizeSupplierCategoryPath((string) $supplierProduct->category_name),
            'category_exists' => $category !== null,
            'supplier_price' => $supplierProduct->price,
            'recommended_price' => $supplierProduct->recommended_price,
            'pricing_rule_applied' => $pricing['rule_label'],
            'pricing_rule_scope' => $pricing['rule_scope'],
            'matched_pricing_rule' => $pricing['matched_pricing_rule'],
            'winning_pricing_rule' => $pricing['winning_pricing_rule'],
            'pricing_inheritance' => $pricing['pricing_inheritance'],
            'pricing_rule_reason' => $pricing['pricing_rule_reason'],
            'margin_rule' => $pricing['margin_rule'],
            'margin_amount' => $pricing['margin_amount'],
            'margin_applied' => $pricing['margin_amount'],
            'profit_amount' => $profitAmount,
            'margin_percent' => $marginPercent,
            'final_calculated_selling_price' => $pricing['final_selling_price'],
            'sale_price' => $pricing['sale_price'],
            'pricing_applies' => $pricing['pricing_applies'],
            'stock_quantity' => $supplierProduct->quantity,
            'stock_status' => $availability?->code ?? (($supplierProduct->quantity ?? 0) > 0 ? 'in_stock' : 'out_of_stock'),
            'availability_status' => $availability?->name,
            'image_count' => $imageCount,
            'missing_images' => $imageCount === 0,
            'missing_ean' => blank($supplierProduct->ean),
            'target_catalog_action' => $action,
            'matched_by' => $matches['matched_by'],
            'matched_by_display' => $this->matchedByDisplay($matches['matched_by']),
            'reason' => $this->reason($action),
            'result' => $this->result($action),
            'target_product_id' => $targetProduct?->id,
            'target_product_sku' => $targetProduct?->sku,
            'target_product_name' => $targetProduct?->name,
            'current_price' => $targetProduct?->price,
            'new_price' => $pricing['final_selling_price'],
            'current_stock' => $targetProduct?->quantity,
            'new_stock' => $supplierProduct->quantity,
            'conflict_reasons' => $this->conflictReasons($supplierProduct, $matches, $duplicateSupplierRows),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function supplierProductsQuery(array $filters): Builder
    {
        return SupplierProduct::query()
            ->with('supplier')
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, mixed $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['category'] ?? null, fn (Builder $query, mixed $category) => $query->where('category_name', 'like', "%{$category}%"))
            ->when($filters['brand'] ?? null, fn (Builder $query, mixed $brand) => $query->where('brand_name', 'like', "%{$brand}%"))
            ->when($filters['stock_status'] ?? null, function (Builder $query, mixed $stockStatus): void {
                match ($stockStatus) {
                    'in_stock' => $query->where('quantity', '>', 0),
                    'out_of_stock' => $query->where(fn (Builder $query) => $query->whereNull('quantity')->orWhere('quantity', '<=', 0)),
                    default => null,
                };
            })
            ->when($filters['search'] ?? null, function (Builder $query, mixed $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('supplier_sku', 'like', "%{$search}%")
                        ->orWhere('ean', 'like', "%{$search}%")
                        ->orWhere('mpn', 'like', "%{$search}%");
                });
            })
            ->orderBy('id');
    }

    /**
     * @return array{products: Collection<int, Product>, matched_by: array<int, string>}
     */
    protected function matchCatalogProducts(SupplierProduct $supplierProduct): array
    {
        if ($supplierProduct->product_id) {
            $product = Product::query()->find($supplierProduct->product_id);

            if ($product) {
                return [
                    'products' => collect([$product]),
                    'matched_by' => ['manual_mapping'],
                ];
            }
        }

        $matches = collect();
        $matchedBy = [];

        foreach ([
            'sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
        ] as $field => $value) {
            if (blank($value)) {
                continue;
            }

            $fieldMatches = Product::query()->where($field, $value)->get();

            if ($fieldMatches->isNotEmpty()) {
                $matchedBy[] = $field;
                $matches = $matches->merge($fieldMatches);
            }
        }

        return [
            'products' => $matches->unique('id')->values(),
            'matched_by' => array_values(array_unique($matchedBy)),
        ];
    }

    /**
     * @param  array{products: Collection<int, Product>, matched_by: array<int, string>}  $matches
     */
    protected function action(SupplierProduct $supplierProduct, array $matches, bool $duplicateSupplierRows): string
    {
        if ($this->identifiers($supplierProduct) === []) {
            return 'skip';
        }

        if ($duplicateSupplierRows || $matches['products']->count() > 1) {
            return 'conflict';
        }

        return $matches['products']->isNotEmpty() ? 'update' : 'create';
    }

    /**
     * @return array<string, mixed>
     */
    protected function pricingPreview(SupplierProduct $supplierProduct, ?Product $targetProduct, ?Brand $brand, ?Category $category, string $action): array
    {
        if ($action === 'skip' || $action === 'conflict') {
            return $this->emptyPricingPreview(false);
        }

        $pricingProduct = $targetProduct ? $targetProduct->replicate() : new Product;
        $pricingProduct->id = $targetProduct?->id;
        $pricingProduct->brand_id = $targetProduct?->brand_id ?: $brand?->id;
        $pricingProduct->category_id = $targetProduct?->category_id ?: $category?->id;
        $pricingProduct->source = $targetProduct?->source ?? Product::SOURCE_SUPPLIER_IMPORT;
        $pricingProduct->apply_pricing_rules = (bool) ($targetProduct?->apply_pricing_rules ?? false);

        $pricingApplies = $action === 'create' || $pricingProduct->shouldApplyPricingEngine();

        if (! $pricingApplies) {
            return $this->emptyPricingPreview(false);
        }

        $pricing = $this->pricingEngine->calculateForSupplierProduct($supplierProduct, $pricingProduct, $category);
        $rule = $pricing['rule_id'] ? PricingRule::query()->find($pricing['rule_id']) : null;
        $inheritance = $this->pricingInheritance($pricingProduct, $category, $supplierProduct, $rule);

        return [
            'pricing_applies' => true,
            'rule_label' => $this->pricingRuleLabel($pricing['rule_scope']),
            'rule_scope' => $pricing['rule_scope'],
            'matched_pricing_rule' => $this->displayPricingRule($rule, $supplierProduct),
            'winning_pricing_rule' => $this->displayPricingRule($rule, $supplierProduct),
            'pricing_inheritance' => $inheritance,
            'pricing_rule_reason' => $this->pricingRuleReason($rule),
            'margin_rule' => $this->marginRuleLabel($rule),
            'margin_amount' => $pricing['margin_price'] !== null ? round($pricing['margin_price'] - $pricing['normalized_purchase_cost'], 2) : null,
            'final_selling_price' => $pricing['final_selling_price'],
            'sale_price' => $pricing['sale_price'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPricingPreview(bool $pricingApplies): array
    {
        return [
            'pricing_applies' => $pricingApplies,
            'rule_label' => null,
            'rule_scope' => null,
            'matched_pricing_rule' => null,
            'winning_pricing_rule' => null,
            'pricing_inheritance' => [],
            'pricing_rule_reason' => null,
            'margin_rule' => null,
            'margin_amount' => null,
            'final_selling_price' => null,
            'sale_price' => null,
        ];
    }

    protected function pricingRuleLabel(?string $scope): ?string
    {
        return match ($scope) {
            'category_brand_supplier' => 'Category + Brand + Supplier',
            'category_brand' => 'Category + Brand',
            'category_supplier' => 'Category + Supplier',
            'category' => 'Category',
            'brand' => 'Brand',
            'supplier' => 'Supplier',
            'price_range' => 'Price Range',
            'global' => 'Global Default',
            'product' => 'Product',
            default => $scope,
        };
    }

    protected function displayPricingRule(?PricingRule $rule, SupplierProduct $supplierProduct): ?string
    {
        if (! $rule) {
            return null;
        }

        return match ($rule->scope_type) {
            PricingRule::SCOPE_PRODUCT => 'Product '.$rule->product?->name,
            PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER => trim(($rule->category?->name ?? 'Category').' + '.($rule->brand?->name ?? 'Brand').' + Supplier '.($rule->supplier?->company_name ?? $supplierProduct->supplier?->company_name)),
            PricingRule::SCOPE_CATEGORY_BRAND => trim(($rule->category?->name ?? 'Category').' + '.($rule->brand?->name ?? 'Brand')),
            PricingRule::SCOPE_CATEGORY_SUPPLIER => trim(($rule->category?->name ?? 'Category').' + Supplier '.($rule->supplier?->company_name ?? $supplierProduct->supplier?->company_name)),
            PricingRule::SCOPE_CATEGORY => $rule->category?->name ?? 'Category',
            PricingRule::SCOPE_BRAND => $rule->brand?->name ?? 'Brand',
            PricingRule::SCOPE_SUPPLIER => 'Supplier '.($rule->supplier?->company_name ?? $supplierProduct->supplier?->company_name),
            PricingRule::SCOPE_PRICE_RANGE => 'Price Range',
            PricingRule::SCOPE_GLOBAL => 'Global Default',
            default => $rule->name,
        };
    }

    protected function marginRuleLabel(?PricingRule $rule): ?string
    {
        if (! $rule) {
            return null;
        }

        $value = rtrim(rtrim(number_format((float) $rule->margin_value, 4, '.', ''), '0'), '.');

        return $rule->margin_type === PricingRule::MARGIN_FIXED ? "+{$value} EUR" : "{$value}%";
    }

    /**
     * @return array<int, string>
     */
    protected function pricingInheritance(Product $product, ?Category $category, SupplierProduct $supplierProduct, ?PricingRule $winningRule): array
    {
        $rules = collect();

        $this->appendRule($rules, PricingRule::query()->active()->where('scope_type', PricingRule::SCOPE_GLOBAL)->orderBy('sort_order')->first());

        if ($supplierProduct->supplier_id) {
            $this->appendRule(
                $rules,
                PricingRule::query()
                    ->active()
                    ->where('scope_type', PricingRule::SCOPE_SUPPLIER)
                    ->where('supplier_id', $supplierProduct->supplier_id)
                    ->orderBy('sort_order')
                    ->first(),
            );
        }

        foreach (array_reverse($this->categoryHierarchy($category)) as $categoryId) {
            $this->appendRule(
                $rules,
                PricingRule::query()
                    ->active()
                    ->where('scope_type', PricingRule::SCOPE_CATEGORY)
                    ->where('category_id', $categoryId)
                    ->orderBy('sort_order')
                    ->first(),
            );
        }

        foreach (array_reverse($this->categoryHierarchy($category)) as $categoryId) {
            if ($supplierProduct->supplier_id) {
                $this->appendRule(
                    $rules,
                    PricingRule::query()
                        ->active()
                        ->where('scope_type', PricingRule::SCOPE_CATEGORY_SUPPLIER)
                        ->where('category_id', $categoryId)
                        ->where('supplier_id', $supplierProduct->supplier_id)
                        ->orderBy('sort_order')
                        ->first(),
                );
            }

            if ($product->brand_id) {
                $this->appendRule(
                    $rules,
                    PricingRule::query()
                        ->active()
                        ->where('scope_type', PricingRule::SCOPE_CATEGORY_BRAND)
                        ->where('category_id', $categoryId)
                        ->where('brand_id', $product->brand_id)
                        ->orderBy('sort_order')
                        ->first(),
                );
            }

            if ($product->brand_id && $supplierProduct->supplier_id) {
                $this->appendRule(
                    $rules,
                    PricingRule::query()
                        ->active()
                        ->where('scope_type', PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER)
                        ->where('category_id', $categoryId)
                        ->where('brand_id', $product->brand_id)
                        ->where('supplier_id', $supplierProduct->supplier_id)
                        ->orderBy('sort_order')
                        ->first(),
                );
            }
        }

        if ($product->brand_id) {
            $this->appendRule(
                $rules,
                PricingRule::query()
                    ->active()
                    ->where('scope_type', PricingRule::SCOPE_BRAND)
                    ->where('brand_id', $product->brand_id)
                    ->orderBy('sort_order')
                    ->first(),
            );
        }

        $this->appendRule($rules, $winningRule);

        return $rules
            ->unique('id')
            ->map(fn (PricingRule $rule): ?string => $this->displayPricingRule($rule, $supplierProduct))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PricingRule>  $rules
     */
    protected function appendRule(Collection $rules, ?PricingRule $rule): void
    {
        if ($rule) {
            $rules->push($rule);
        }
    }

    protected function pricingRuleReason(?PricingRule $rule): ?string
    {
        return $rule ? 'First active matching rule by priority: '.$this->pricingRuleLabel($rule->scope_type) : null;
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

    /**
     * @param  array<int, string>  $matchedBy
     */
    protected function matchedByDisplay(array $matchedBy): string
    {
        if ($matchedBy === []) {
            return 'None';
        }

        return collect($matchedBy)
            ->map(fn (string $match): string => match ($match) {
                'sku' => 'SKU',
                'ean' => 'EAN',
                'mpn' => 'MPN',
                'manual_mapping' => 'Manual mapping',
                default => Str::title(str_replace('_', ' ', $match)),
            })
            ->implode(' / ');
    }

    protected function reason(string $action): string
    {
        return match ($action) {
            'create' => 'New catalog product',
            'update' => 'Existing catalog product matched',
            'conflict' => 'Conflicting identifiers require review',
            'skip' => 'Missing required identifiers',
            default => 'Preview generated',
        };
    }

    protected function result(string $action): string
    {
        return match ($action) {
            'create' => 'New catalog product will be created',
            'update' => 'Existing catalog product will be updated',
            'conflict' => 'Conflict detected',
            'skip' => 'Supplier product will be skipped',
            default => 'No catalog change will be made during preview',
        };
    }

    protected function findExistingBrand(?string $name): ?Brand
    {
        if (blank($name)) {
            return null;
        }

        return Brand::query()->where('slug', Str::slug($name))->first();
    }

    protected function findExistingCategory(?string $categoryPath): ?Category
    {
        if (blank($categoryPath)) {
            return null;
        }

        $normalizedPath = $this->normalizeSupplierCategoryPath($categoryPath);
        $segments = preg_split('/\s*(?:>|\/|\|)\s*/', trim($normalizedPath)) ?: [];
        $lastSegment = collect($segments)->map(fn (string $segment): string => trim($segment))->filter()->last();

        return $lastSegment ? Category::query()->where('slug', Str::slug($lastSegment))->first() : null;
    }

    protected function normalizeSupplierCategoryPath(string $categoryPath): string
    {
        $paths = collect(explode(',', $categoryPath))
            ->map(fn (string $path): string => trim($path))
            ->filter()
            ->reject(fn (string $path): bool => in_array(Str::lower($path), ['apcom', 'eol products'], true))
            ->values();

        return $paths->first(fn (string $path): bool => str_contains($path, '>'))
            ?? $paths->first()
            ?? trim($categoryPath);
    }

    protected function hasDuplicateSupplierRows(SupplierProduct $supplierProduct): bool
    {
        $identifiers = $this->identifiers($supplierProduct);

        if ($identifiers === []) {
            return false;
        }

        return SupplierProduct::query()
            ->where('id', '!=', $supplierProduct->id)
            ->where('supplier_id', $supplierProduct->supplier_id)
            ->where(function (Builder $query) use ($identifiers): void {
                foreach ($identifiers as $field => $value) {
                    $query->orWhere($field === 'sku' ? 'supplier_sku' : $field, $value);
                }
            })
            ->exists();
    }

    /**
     * @param  array{products: Collection<int, Product>, matched_by: array<int, string>}  $matches
     * @return array<int, string>
     */
    protected function conflictReasons(SupplierProduct $supplierProduct, array $matches, bool $duplicateSupplierRows): array
    {
        $reasons = [];

        if ($duplicateSupplierRows) {
            $reasons[] = 'duplicate_supplier_identifiers';
        }

        if ($matches['products']->count() > 1) {
            $reasons[] = 'multiple_catalog_matches';
        }

        foreach ($this->identifiers($supplierProduct) as $field => $value) {
            $column = $field === 'sku' ? 'sku' : $field;
            $count = Product::query()->where($column, $value)->count();

            if ($count > 1) {
                $reasons[] = "duplicate_catalog_{$field}";
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return array<string, string>
     */
    protected function identifiers(SupplierProduct $supplierProduct): array
    {
        return array_filter([
            'sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
        ], fn ($value): bool => filled($value));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    protected function summary(Collection $rows): array
    {
        return [
            'total_staged_products' => $rows->count(),
            'to_create' => $rows->where('target_catalog_action', 'create')->count(),
            'to_update' => $rows->where('target_catalog_action', 'update')->count(),
            'to_skip' => $rows->where('target_catalog_action', 'skip')->count(),
            'conflicts' => $rows->where('target_catalog_action', 'conflict')->count(),
            'missing_categories' => $rows->where('category_exists', false)->count(),
            'missing_images' => $rows->where('missing_images', true)->count(),
            'missing_ean' => $rows->where('missing_ean', true)->count(),
            'average_margin' => round((float) $rows->whereNotNull('margin_percent')->avg('margin_percent'), 2),
            'estimated_revenue' => round((float) $rows->sum(fn (array $row): float => (float) ($row['final_calculated_selling_price'] ?? 0) * (int) ($row['stock_quantity'] ?? 0)), 2),
            'estimated_profit' => round((float) $rows->sum(fn (array $row): float => (float) ($row['profit_amount'] ?? 0) * (int) ($row['stock_quantity'] ?? 0)), 2),
        ];
    }

    protected function matchesActionFilter(array $row, mixed $action): bool
    {
        return blank($action) || $row['target_catalog_action'] === $action;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function sortRows(Collection $rows, array $filters): Collection
    {
        $column = $filters['sort_column'] ?? null;

        if (! in_array($column, [
            'product_name',
            'supplier_price',
            'final_calculated_selling_price',
            'profit_amount',
            'margin_percent',
            'winning_pricing_rule',
            'target_catalog_action',
            'stock_quantity',
            'supplier_name',
            'normalized_category',
        ], true)) {
            return $rows;
        }

        $direction = ($filters['sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $rows->sortBy(
            fn (array $row): mixed => $row[$column] ?? null,
            SORT_REGULAR,
            $direction === 'desc',
        );
    }

    protected function matchesQuickFilter(array $row, mixed $quickFilter): bool
    {
        return match ($quickFilter) {
            'create', 'update', 'conflict' => $row['target_catalog_action'] === $quickFilter,
            'apcom' => Str::lower((string) $row['supplier_name']) === 'apcom',
            'missing_ean' => (bool) $row['missing_ean'],
            'zero_stock' => (int) ($row['stock_quantity'] ?? 0) <= 0,
            'missing_images' => (bool) $row['missing_images'],
            default => true,
        };
    }

    protected function profitAmount(mixed $supplierPrice, mixed $finalSellingPrice): ?float
    {
        if ($supplierPrice === null || $finalSellingPrice === null) {
            return null;
        }

        return round((float) $finalSellingPrice - (float) $supplierPrice, 2);
    }

    protected function marginPercent(mixed $supplierPrice, ?float $profitAmount): ?float
    {
        if ($supplierPrice === null || $profitAmount === null || (float) $supplierPrice <= 0) {
            return null;
        }

        return round(($profitAmount / (float) $supplierPrice) * 100, 2);
    }

    protected function normalizeLimit(int|string $limit): int
    {
        if ($limit === 'all') {
            return 100000;
        }

        return in_array((int) $limit, [50, 100], true) ? (int) $limit : 50;
    }

    /**
     * @return array<int, string>
     */
    protected function extractImageUrls(array $payload): array
    {
        $urls = [];
        $keys = ['image', 'Image', 'image_url', 'ImageURL', 'ImageUrl', 'Picture', 'picture'];

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $keys, true) && is_array($value)) {
                foreach ($value as $nestedValue) {
                    if (is_string($nestedValue) && Str::startsWith(trim($nestedValue), ['http://', 'https://'])) {
                        $urls[] = trim($nestedValue);

                        continue;
                    }

                    if (is_array($nestedValue)) {
                        foreach ($this->extractImageUrls($nestedValue) as $url) {
                            $urls[] = $url;
                        }
                    }
                }

                continue;
            }

            if (is_array($value)) {
                foreach ($this->extractImageUrls($value) as $url) {
                    $urls[] = $url;
                }

                continue;
            }

            if (! in_array((string) $key, $keys, true) || blank($value)) {
                continue;
            }

            $url = trim((string) $value);

            if (Str::startsWith($url, ['http://', 'https://'])) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }
}
