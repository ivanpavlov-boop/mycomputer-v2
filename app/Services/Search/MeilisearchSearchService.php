<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Models\ProductBundle;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Client as MeilisearchClient;
use Throwable;

class MeilisearchSearchService extends DatabaseSearchService
{
    public function search(array $criteria): array
    {
        if (config('scout.driver') !== 'meilisearch') {
            return parent::search($criteria);
        }

        try {
            $criteria = $this->normalizedCriteria($criteria);
            $queryText = (string) ($criteria['q'] ?? $criteria['search'] ?? '');
            $page = (int) ($criteria['page'] ?? 1);
            $perPage = $this->productQueryFilters->perPage($criteria);

            $builder = Product::search($queryText === '' ? '*' : $queryText, function ($index, string $query, array $options) use ($criteria) {
                $options['filter'] = $this->meilisearchFilters($criteria);

                if ($sort = $this->meilisearchSort($criteria['sort'] ?? null)) {
                    $options['sort'] = [$sort];
                }

                return $index->search($query, $options);
            })->query(fn ($query) => $query->published()->with(['brand', 'category', 'images', 'availabilityStatus']));

            $products = $builder->paginate($perPage, 'page', $page);

            return [
                'products' => $products,
                'bundles' => $this->meilisearchBundles($criteria, $queryText, $page),
                'categories' => $this->matchingCategories($queryText),
                'brands' => $this->matchingBrands($queryText),
                'suggestions' => $this->suggestions($queryText),
                'available_filters' => $this->categoryAwareAvailableFilters($criteria),
                'engine' => 'meilisearch',
                'meilisearch_ready' => true,
            ];
        } catch (Throwable) {
            return parent::search($criteria);
        }
    }

    public function indexedProductsCount(): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            return parent::indexedProductsCount();
        }

        try {
            $stats = $this->client()->index((new Product)->searchableAs())->stats();
            $bundleStats = $this->client()->index((new ProductBundle)->searchableAs())->stats();

            return (int) ($stats['numberOfDocuments'] ?? 0) + (int) ($bundleStats['numberOfDocuments'] ?? 0);
        } catch (Throwable) {
            return parent::indexedProductsCount();
        }
    }

    public function status(): array
    {
        if (config('scout.driver') !== 'meilisearch') {
            return parent::status();
        }

        try {
            $health = $this->client()->health();

            return [
                'engine' => 'meilisearch',
                'available' => ($health['status'] ?? null) === 'available',
                'indexed_products_count' => $this->indexedProductsCount(),
                'last_indexed_at' => Cache::get('search.last_indexed_at'),
                'message' => 'Meilisearch is configured.',
            ];
        } catch (Throwable $exception) {
            return [
                'engine' => 'meilisearch',
                'available' => false,
                'indexed_products_count' => parent::indexedProductsCount(),
                'last_indexed_at' => Cache::get('search.last_indexed_at'),
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function reindex(): int
    {
        $count = 0;

        Product::query()
            ->published()
            ->with(['brand', 'category.parent', 'availabilityStatus', 'attributeValues.attribute.group', 'attributeValues.value', 'attributeValues.canonicalAttribute', 'attributeValues.canonicalAttributeValue'])
            ->chunkById(500, function ($products) use (&$count): void {
                $products->searchable();
                $count += $products->count();
            });

        ProductBundle::query()
            ->available()
            ->with(['items.product.brand', 'options.product.brand'])
            ->chunkById(500, function ($bundles) use (&$count): void {
                $bundles->searchable();
                $count += $bundles->count();
            });

        Cache::forever('search.last_indexed_at', now()->toISOString());

        return $count;
    }

    public function flush(): void
    {
        Product::removeAllFromSearch();
        ProductBundle::removeAllFromSearch();
    }

    protected function meilisearchBundles(array $criteria, string $queryText, int $page)
    {
        $perPage = min((int) ($criteria['bundle_per_page'] ?? 8), 24);

        try {
            return ProductBundle::search($queryText === '' ? '*' : $queryText, function ($index, string $query, array $options) {
                $options['filter'] = ['active = true'];

                return $index->search($query, $options);
            })
                ->query(fn ($query) => $query->with([
                    'items.product' => fn ($product) => $product->published()->with(['images', 'brand']),
                    'options.product' => fn ($product) => $product->published()->with(['images', 'brand']),
                ]))
                ->paginate($perPage, 'bundle_page', $page);
        } catch (Throwable) {
            return $this->matchingBundles($criteria);
        }
    }

    protected function meilisearchFilters(array $criteria): array
    {
        $filters = ['active = true'];

        foreach ([
            'category' => 'category_slug',
            'brand' => 'brand_slug',
            'stock_status' => 'stock_status',
            'availability' => 'availability_status_code',
            'availability_status' => 'availability_status_code',
        ] as $input => $field) {
            if (filled($criteria[$input] ?? null)) {
                $filters[] = $field.' = "'.addslashes((string) $criteria[$input]).'"';
            }
        }

        if (filled($criteria['availability_statuses'] ?? null)) {
            $filters[] = '('.implode(' OR ', array_map(
                fn ($code): string => 'availability_status_code = "'.addslashes((string) $code).'"',
                (array) $criteria['availability_statuses']
            )).')';
        }

        foreach (['featured', 'bestseller', 'new_product'] as $flag) {
            if (array_key_exists($flag, $criteria) && filled($criteria[$flag])) {
                $filters[] = $flag.' = '.($this->toFilterBool($criteria[$flag]) ? 'true' : 'false');
            }
        }

        if (filled($criteria['price_min'] ?? null)) {
            $filters[] = 'price >= '.(float) $criteria['price_min'];
        }

        if (filled($criteria['price_max'] ?? null)) {
            $filters[] = 'price <= '.(float) $criteria['price_max'];
        }

        foreach ((array) ($criteria['attributes'] ?? []) as $attribute) {
            if (! filled($attribute)) {
                continue;
            }

            $value = addslashes((string) $attribute);

            $filters[] = '(attributes.slug = "'.$value.'" OR attributes.value_slug = "'.$value.'")';
        }

        return $filters;
    }

    protected function meilisearchSort(?string $sort): ?string
    {
        return match ($sort) {
            'price_asc' => 'price:asc',
            'price_desc' => 'price:desc',
            'newest' => 'published_at:desc',
            'bestseller' => 'bestseller:desc',
            'featured' => 'featured:desc',
            'availability' => 'availability_sort_order:asc',
            'name_asc' => 'name:asc',
            'name_desc' => 'name:desc',
            default => null,
        };
    }

    protected function categoryAwareAvailableFilters(array $criteria): array
    {
        if (filled($criteria['category'] ?? null)) {
            return $this->categoryFilters((string) $criteria['category']);
        }

        return parent::search(array_diff_key($criteria, ['page' => true, 'per_page' => true]))['available_filters'];
    }

    protected function client(): MeilisearchClient
    {
        return new MeilisearchClient(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key')
        );
    }

    private function toFilterBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
