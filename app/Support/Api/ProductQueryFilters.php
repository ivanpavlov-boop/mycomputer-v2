<?php

namespace App\Support\Api;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class ProductQueryFilters
{
    public function publicQuery(): Builder
    {
        return Product::query()
            ->published()
            ->with(['brand', 'category', 'images', 'availabilityStatus']);
    }

    public function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['category'] ?? null), fn (Builder $query) => $query->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category'])))
            ->when(filled($filters['brand'] ?? null), fn (Builder $query) => $query->whereHas('brand', fn (Builder $brand) => $brand->where('slug', $filters['brand'])))
            ->when(filled($filters['price_min'] ?? null), fn (Builder $query) => $query->where('price', '>=', $filters['price_min']))
            ->when(filled($filters['price_max'] ?? null), fn (Builder $query) => $query->where('price', '<=', $filters['price_max']))
            ->when(filled($filters['stock_status'] ?? null), fn (Builder $query) => $query->where('stock_status', $filters['stock_status']))
            ->when(filled($filters['availability'] ?? null), fn (Builder $query) => $query->whereHas('availabilityStatus', fn (Builder $availability) => $availability->where('code', $filters['availability'])))
            ->when(filled($filters['availability_status'] ?? null), fn (Builder $query) => $query->whereHas('availabilityStatus', fn (Builder $availability) => $availability->where('code', $filters['availability_status'])))
            ->when(filled($filters['availability_statuses'] ?? null), fn (Builder $query) => $query->whereHas('availabilityStatus', fn (Builder $availability) => $availability->whereIn('code', (array) $filters['availability_statuses'])))
            ->when(array_key_exists('featured', $filters) && filled($filters['featured']), fn (Builder $query) => $query->where('featured', $this->toBool($filters['featured'])))
            ->when(array_key_exists('new_product', $filters) && filled($filters['new_product']), fn (Builder $query) => $query->where('new_product', $this->toBool($filters['new_product'])))
            ->when(array_key_exists('bestseller', $filters) && filled($filters['bestseller']), fn (Builder $query) => $query->where('bestseller', $this->toBool($filters['bestseller'])))
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $this->search($query, (string) $filters['search']))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $this->search($query, (string) $filters['q']))
            ->when(filled($filters['attributes'] ?? null), fn (Builder $query) => $this->attributes($query, (array) $filters['attributes']));
    }

    public function sort(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'newest' => $query->latest('published_at'),
            'bestseller' => $query->orderByDesc('bestseller')->latest('published_at'),
            'featured' => $query->orderByDesc('featured')->latest('published_at'),
            'name_asc' => $query->orderBy('name'),
            'name_desc' => $query->orderByDesc('name'),
            'relevance' => $query->latest('published_at'),
            default => $query->latest('published_at'),
        };
    }

    public function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? 24), 1), 100);
    }

    private function search(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('ean', 'like', "%{$search}%")
                ->orWhere('mpn', 'like', "%{$search}%")
                ->orWhere('short_description', 'like', "%{$search}%")
                ->orWhere('searchable_keywords', 'like', "%{$search}%")
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$search}%"))
                ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', "%{$search}%"));
        });
    }

    private function attributes(Builder $query, array $attributes): Builder
    {
        foreach ($attributes as $attribute) {
            $query->whereHas('attributeValues', function (Builder $query) use ($attribute): void {
                $query
                    ->whereHas('canonicalAttribute', fn (Builder $query) => $query->where('code', $attribute))
                    ->orWhereHas('canonicalAttributeValue', fn (Builder $query) => $query->where('normalized_value', $attribute))
                    ->orWhereHas('attribute', fn (Builder $query) => $query->where('slug', $attribute))
                    ->orWhereHas('value', fn (Builder $query) => $query->where('slug', $attribute));
            });
        }

        return $query;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
