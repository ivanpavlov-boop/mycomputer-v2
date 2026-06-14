<?php

namespace App\Services\Search;

use App\Models\Brand;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Support\Api\ProductQueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSearchService implements SearchServiceInterface
{
    public function __construct(
        protected ProductQueryFilters $productQueryFilters,
    ) {}

    public function search(array $criteria): array
    {
        $criteria = $this->normalizedCriteria($criteria);
        $query = $this->productQueryFilters->apply($this->productQueryFilters->publicQuery(), $criteria);
        $query = $this->productQueryFilters->sort($query, $criteria['sort'] ?? null);

        $products = $query->paginate(
            perPage: $this->productQueryFilters->perPage($criteria),
            page: (int) ($criteria['page'] ?? 1),
        );

        $queryText = (string) ($criteria['q'] ?? $criteria['search'] ?? '');

        return [
            'products' => $products,
            'bundles' => $this->matchingBundles($criteria),
            'categories' => $this->matchingCategories($queryText),
            'brands' => $this->matchingBrands($queryText),
            'suggestions' => $this->suggestions($queryText),
            'available_filters' => $this->availableFilters(
                $this->productQueryFilters->apply($this->productQueryFilters->publicQuery(), $criteria)
            ),
            'engine' => 'database',
            'meilisearch_ready' => false,
        ];
    }

    public function suggestions(string $query): array
    {
        $query = trim($this->normalizeQuery($query));

        if ($query === '') {
            return [];
        }

        $products = Product::query()
            ->published()
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('ean', 'like', "%{$query}%")
                    ->orWhere('mpn', 'like', "%{$query}%")
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', "%{$query}%"));
            })
            ->orderByDesc('featured')
            ->limit(5)
            ->get(['name', 'sku']);

        $bundles = ProductBundle::query()
            ->available()
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('slug', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('type', 'like', "%{$query}%")
                    ->orWhere('pricing_type', 'like', "%{$query}%")
                    ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', "%{$query}%")->orWhere('sku', 'like', "%{$query}%"))
                    ->orWhereHas('items.product.brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('options.product', fn (Builder $product) => $product->where('name', 'like', "%{$query}%")->orWhere('sku', 'like', "%{$query}%"))
                    ->orWhereHas('options.product.brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$query}%"));
            })
            ->orderBy('sort_order')
            ->limit(5)
            ->get(['name', 'slug']);

        return $products
            ->flatMap(fn (Product $product): array => array_filter([$product->name, $product->sku]))
            ->merge($bundles->flatMap(fn (ProductBundle $bundle): array => array_filter([$bundle->name, $bundle->slug])))
            ->merge([
                $query,
                $query.' laptop',
                $query.' monitor',
            ])
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    public function categoryFilters(string $slug): array
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $categoryIds = $this->categoryAndDescendantIds($category);
        $baseQuery = Product::query()->published()->whereIn('category_id', $categoryIds);

        return $this->availableFilters($baseQuery);
    }

    public function indexedProductsCount(): int
    {
        return Product::query()->published()->count();
    }

    public function status(): array
    {
        return [
            'engine' => 'database',
            'available' => true,
            'indexed_products_count' => $this->indexedProductsCount(),
            'last_indexed_at' => null,
            'message' => 'Using database fallback search.',
        ];
    }

    public function reindex(): int
    {
        return Product::query()->published()->count();
    }

    public function flush(): void
    {
        //
    }

    protected function normalizedCriteria(array $criteria): array
    {
        $query = (string) ($criteria['q'] ?? $criteria['search'] ?? '');

        if (preg_match('/\b(?:do|under|max)\s+(\d+(?:[.,]\d+)?)/iu', $query, $matches)) {
            $criteria['price_max'] ??= (float) str_replace(',', '.', $matches[1]);
        }

        $normalized = $this->normalizeQuery($query);

        if ($normalized !== $query) {
            $criteria['q'] = $normalized;
            unset($criteria['search']);
        }

        return $criteria;
    }

    protected function normalizeQuery(string $query): string
    {
        $query = trim(Str::lower($query));

        $replacements = [
            'asuss' => 'asus',
            'lenowo' => 'lenovo',
            'samzung' => 'samsung',
        ];

        foreach ($replacements as $typo => $replacement) {
            $query = preg_replace('/\b'.preg_quote($typo, '/').'\b/u', $replacement, $query) ?? $query;
        }

        $query = preg_replace('/\b(?:za|for|do|under|max)\b/u', ' ', $query) ?? $query;

        return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    }

    protected function matchingCategories(string $query): mixed
    {
        $query = $this->normalizeQuery($query);

        return Category::query()
            ->where('is_active', true)
            ->when($query !== '', fn (Builder $builder) => $builder->where('name', 'like', "%{$query}%"))
            ->orderBy('sort_order')
            ->limit(10)
            ->get();
    }

    protected function matchingBrands(string $query): mixed
    {
        $query = $this->normalizeQuery($query);

        return Brand::query()
            ->where('is_active', true)
            ->when($query !== '', fn (Builder $builder) => $builder->where('name', 'like', "%{$query}%"))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    protected function matchingBundles(array $criteria): mixed
    {
        $query = $this->normalizeQuery((string) ($criteria['q'] ?? $criteria['search'] ?? ''));

        return ProductBundle::query()
            ->available()
            ->with(['items.product.images', 'items.product.brand', 'options.product.images', 'options.product.brand'])
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder
                        ->where('name', 'like', "%{$query}%")
                        ->orWhere('slug', 'like', "%{$query}%")
                        ->orWhere('short_description', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhere('type', 'like', "%{$query}%")
                        ->orWhere('pricing_type', 'like', "%{$query}%")
                        ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', "%{$query}%")->orWhere('sku', 'like', "%{$query}%"))
                        ->orWhereHas('items.product.brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$query}%"))
                        ->orWhereHas('options.product', fn (Builder $product) => $product->where('name', 'like', "%{$query}%")->orWhere('sku', 'like', "%{$query}%"))
                        ->orWhereHas('options.product.brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$query}%"));
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(
                perPage: min((int) ($criteria['bundle_per_page'] ?? 8), 24),
                page: (int) ($criteria['page'] ?? 1),
                pageName: 'bundle_page',
            );
    }

    protected function availableFilters(Builder $baseQuery): array
    {
        $productIds = (clone $baseQuery)->pluck('products.id');

        if ($productIds->isEmpty()) {
            return [
                'brands' => [],
                'price_range' => ['min' => null, 'max' => null],
                'stock_statuses' => [],
                'availability_statuses' => [],
                'attributes' => [],
            ];
        }

        $brands = Product::query()
            ->whereIn('products.id', $productIds)
            ->join('brands', 'products.brand_id', '=', 'brands.id')
            ->select(
                'brands.id',
                'brands.name',
                'brands.slug',
                'brands.is_active',
                DB::raw('count(products.id) as products_count')
            )
            ->groupBy('brands.id', 'brands.name', 'brands.slug', 'brands.is_active')
            ->orderBy('brands.name')
            ->get()
            ->map(fn (object $brand): array => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'active' => (bool) $brand->is_active,
                'products_count' => (int) $brand->products_count,
            ])
            ->all();

        $priceRange = [
            'min' => (clone $baseQuery)->min('price'),
            'max' => (clone $baseQuery)->max('price'),
        ];

        $stockStatuses = Product::query()
            ->whereIn('id', $productIds)
            ->select('stock_status', DB::raw('count(*) as products_count'))
            ->groupBy('stock_status')
            ->pluck('products_count', 'stock_status')
            ->map(fn (int $count): int => $count)
            ->all();

        $availabilityStatuses = Product::query()
            ->whereIn('products.id', $productIds)
            ->join('availability_statuses', 'products.availability_status_id', '=', 'availability_statuses.id')
            ->select(
                'availability_statuses.code',
                'availability_statuses.name',
                'availability_statuses.color',
                'availability_statuses.icon',
                'availability_statuses.allow_purchase',
                'availability_statuses.sort_order',
                DB::raw('count(products.id) as products_count')
            )
            ->groupBy(
                'availability_statuses.code',
                'availability_statuses.name',
                'availability_statuses.color',
                'availability_statuses.icon',
                'availability_statuses.allow_purchase',
                'availability_statuses.sort_order'
            )
            ->orderBy('availability_statuses.sort_order')
            ->get()
            ->map(fn (object $status): array => [
                'code' => $status->code,
                'name' => $status->name,
                'color' => $status->color,
                'icon' => $status->icon,
                'allow_purchase' => (bool) $status->allow_purchase,
                'products_count' => (int) $status->products_count,
            ])
            ->all();

        $attributes = CanonicalAttribute::query()
            ->where('is_filterable', true)
            ->where('is_active', true)
            ->whereHas('catalogAssignments', fn (Builder $assignment) => $assignment->whereIn('product_id', $productIds))
            ->orderBy('sort_order')
            ->get()
            ->map(function (CanonicalAttribute $attribute) use ($productIds): array {
                $values = CanonicalAttributeValue::query()
                    ->where('canonical_attribute_id', $attribute->id)
                    ->whereHas('assignments', fn (Builder $assignment) => $assignment->whereIn('product_id', $productIds))
                    ->withCount(['assignments as products_count' => fn (Builder $assignment) => $assignment->whereIn('product_id', $productIds)])
                    ->orderBy('sort_order')
                    ->get();

                return [
                    'id' => $attribute->id,
                    'code' => $attribute->code,
                    'name' => $attribute->name,
                    'slug' => $attribute->code,
                    'unit' => $attribute->unit,
                    'group' => $attribute->group_name,
                    'values' => $values->map(fn (CanonicalAttributeValue $value): array => [
                        'id' => $value->id,
                        'value' => $value->display_value,
                        'display_value' => $value->display_value,
                        'slug' => $value->normalized_value,
                        'numeric_value' => $value->numeric_value,
                        'unit' => $value->unit,
                        'products_count' => (int) $value->products_count,
                    ])->all(),
                ];
            })
            ->all();

        return [
            'brands' => $brands,
            'price_range' => $priceRange,
            'stock_statuses' => $stockStatuses,
            'availability_statuses' => $availabilityStatuses,
            'attributes' => $attributes,
        ];
    }

    protected function categoryAndDescendantIds(Category $category): array
    {
        $ids = [$category->id];

        foreach ($category->children()->get() as $child) {
            array_push($ids, ...$this->categoryAndDescendantIds($child));
        }

        return $ids;
    }
}
