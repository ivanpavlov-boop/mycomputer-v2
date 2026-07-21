<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductDetailResource;
use App\Models\Product;
use App\Services\Products\PublicProductAttributeFilterService;
use App\Support\Api\ProductQueryFilters;
use App\Support\Localization\Locales;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(
        ProductIndexRequest $request,
        ProductQueryFilters $filters,
        PublicProductAttributeFilterService $attributeFilters,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $selectedAttributes = $validated['attribute_filters'] ?? [];
        $locale = Locales::resolveApiRequest($request);
        $query = $filters->apply($filters->publicQuery(), $validated);
        $filterMetadata = $attributeFilters->describe($query, $selectedAttributes, $locale);
        $attributeFilters->apply($query, $selectedAttributes, $locale);
        $paginator = $filters
            ->sort($query, $validated['sort'] ?? null)
            ->paginate($filters->perPage($validated))
            ->appends($request->query());

        return ProductCardResource::collection($paginator)->additional($filterMetadata);
    }

    public function show(string $slug): ProductDetailResource
    {
        $product = Product::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'brand',
                'category.parent',
                'images',
                'availabilityStatus',
                'attributeValues.attribute.group',
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
