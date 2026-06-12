<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function categories(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with('childrenRecursive')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function brands(): AnonymousResourceCollection
    {
        $brands = Brand::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands);
    }

    public function products(): AnonymousResourceCollection
    {
        $products = Product::query()
            ->published()
            ->with(['brand', 'category', 'images', 'attributes.attribute.group', 'attributes.value'])
            ->latest('published_at')
            ->paginate(24);

        return ProductResource::collection($products);
    }

    public function product(Product $product): ProductResource
    {
        abort_unless($product->active && $product->published_at !== null, 404);

        return ProductResource::make(
            $product->load(['brand', 'category', 'images', 'attributes.attribute.group', 'attributes.value']),
        );
    }
}
