<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrandIndexRequest;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\BrandResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Brand;
use App\Services\Products\PublicProductAttributeFilterService;
use App\Support\Api\ProductQueryFilters;
use App\Support\Localization\Locales;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandController extends Controller
{
    public function index(BrandIndexRequest $request): AnonymousResourceCollection
    {
        $brands = Brand::query()
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands);
    }

    public function show(string $slug): BrandResource
    {
        return BrandResource::make(
            Brand::query()->where('slug', $slug)->where('is_active', true)->firstOrFail(),
        );
    }

    public function products(
        string $slug,
        ProductIndexRequest $request,
        ProductQueryFilters $filters,
        PublicProductAttributeFilterService $attributeFilters,
    ): AnonymousResourceCollection {
        $brand = Brand::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        $validated = $request->validated();
        $selectedAttributes = $validated['attribute_filters'] ?? [];
        $locale = Locales::resolveApiRequest($request);
        $query = $filters->publicQuery()->where('brand_id', $brand->id);
        $query = $filters->apply($query, $validated);
        $filterMetadata = $attributeFilters->describe($query, $selectedAttributes, $locale);
        $attributeFilters->apply($query, $selectedAttributes, $locale);
        $paginator = $filters
            ->sort($query, $validated['sort'] ?? null)
            ->paginate($filters->perPage($validated))
            ->appends($request->query());

        return ProductCardResource::collection($paginator)->additional($filterMetadata);
    }
}
