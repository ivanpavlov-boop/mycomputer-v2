<?php

namespace App\Services\Content;

use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductBundleResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ContentBlock;
use App\Models\Product;
use App\Models\ProductBundle;
use Illuminate\Database\Eloquent\Builder;

class BlockDataResolver
{
    public function resolve(ContentBlock $block): array
    {
        $settings = $block->settings ?? [];
        $source = $settings['source'] ?? null;
        $limit = min((int) ($settings['limit'] ?? 8), 24);

        return match ($source) {
            'featured' => ['products' => ProductCardResource::collection($this->productQuery($settings)->where('featured', true)->limit($limit)->get())->resolve()],
            'newest' => ['products' => ProductCardResource::collection($this->productQuery($settings)->latest()->limit($limit)->get())->resolve()],
            'bestseller' => ['products' => ProductCardResource::collection($this->productQuery($settings)->where('bestseller', true)->limit($limit)->get())->resolve()],
            'category' => ['products' => ProductCardResource::collection($this->productQuery($settings)->where('category_id', $settings['category_id'] ?? 0)->limit($limit)->get())->resolve()],
            'brand' => ['products' => ProductCardResource::collection($this->productQuery($settings)->where('brand_id', $settings['brand_id'] ?? 0)->limit($limit)->get())->resolve()],
            'bundle' => ['bundles' => ProductBundleResource::collection(ProductBundle::query()->available()->limit($limit)->get())->resolve()],
            'categories' => ['categories' => CategoryResource::collection(Category::query()->where('is_active', true)->orderBy('sort_order')->limit($limit)->get())->resolve()],
            'brands' => ['brands' => BrandResource::collection(Brand::query()->where('is_active', true)->orderBy('sort_order')->limit($limit)->get())->resolve()],
            default => [],
        };
    }

    private function productQuery(array $settings): Builder
    {
        return Product::query()
            ->published()
            ->with(['brand', 'category', 'images', 'availabilityStatus'])
            ->when(filled($settings['availability_status'] ?? null), fn (Builder $query) => $query
                ->whereHas('availabilityStatus', fn (Builder $availability) => $availability->where('code', $settings['availability_status'])))
            ->when(filled($settings['availability_statuses'] ?? null), fn (Builder $query) => $query
                ->whereHas('availabilityStatus', fn (Builder $availability) => $availability->whereIn('code', (array) $settings['availability_statuses'])));
    }
}
