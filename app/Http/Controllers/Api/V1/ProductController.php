<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductDetailResource;
use App\Models\Product;
use App\Support\Api\ProductQueryFilters;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request, ProductQueryFilters $filters): AnonymousResourceCollection
    {
        $query = $filters->apply($filters->publicQuery(), $request->validated());

        return ProductCardResource::collection(
            $filters->sort($query, $request->validated('sort'))->paginate($filters->perPage($request->validated())),
        );
    }

    public function show(string $slug): ProductDetailResource
    {
        $product = Product::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'brand',
                'category',
                'images',
                'availabilityStatus',
                'attributes.attribute.group',
                'attributes.value',
                'attributes.canonicalAttribute',
                'attributes.canonicalAttributeValue',
                'relatedProducts' => fn ($query) => $query->published()->with(['brand', 'category', 'images', 'availabilityStatus']),
                'accessoryProducts' => fn ($query) => $query->published()->with(['brand', 'category', 'images', 'availabilityStatus']),
            ])
            ->firstOrFail();

        return ProductDetailResource::make($product);
    }

    public function related(string $slug): AnonymousResourceCollection
    {
        $product = Product::query()->published()->where('slug', $slug)->firstOrFail();

        return ProductCardResource::collection(
            $product->relatedProducts()->published()->with(['brand', 'category', 'images', 'availabilityStatus'])->limit(12)->get(),
        );
    }

    public function accessories(string $slug): AnonymousResourceCollection
    {
        $product = Product::query()->published()->where('slug', $slug)->firstOrFail();

        return ProductCardResource::collection(
            $product->accessoryProducts()->published()->with(['brand', 'category', 'images', 'availabilityStatus'])->limit(12)->get(),
        );
    }
}
