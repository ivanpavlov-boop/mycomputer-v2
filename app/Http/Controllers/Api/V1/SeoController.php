<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeoResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

class SeoController extends Controller
{
    public function product(string $slug): SeoResource
    {
        $product = Product::query()->published()->where('slug', $slug)->with('brand')->firstOrFail();

        return SeoResource::make([
            'meta_title' => $product->meta_title ?? $product->name,
            'meta_description' => $product->meta_description,
            'canonical_url' => '/products/'.$product->slug,
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $product->name,
                'sku' => $product->sku,
                'mpn' => $product->mpn,
                'gtin13' => $product->ean,
                'brand' => $product->brand?->name,
                'offers' => [
                    '@type' => 'Offer',
                    'priceCurrency' => 'BGN',
                    'price' => $product->promo_price ?? $product->price,
                ],
            ],
        ]);
    }

    public function category(string $slug): SeoResource
    {
        $category = Category::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return SeoResource::make([
            'meta_title' => $category->meta_title ?? $category->name,
            'meta_description' => $category->meta_description,
            'canonical_url' => '/categories/'.$category->slug,
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $category->name,
                'description' => $category->description,
            ],
        ]);
    }

    public function brand(string $slug): SeoResource
    {
        $brand = Brand::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return SeoResource::make([
            'meta_title' => $brand->meta_title ?? $brand->name,
            'meta_description' => $brand->meta_description,
            'canonical_url' => '/brands/'.$brand->slug,
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'Brand',
                'name' => $brand->name,
                'url' => $brand->website,
            ],
        ]);
    }
}
