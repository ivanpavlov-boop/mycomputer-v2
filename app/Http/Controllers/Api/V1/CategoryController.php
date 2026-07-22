<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CategoryIndexRequest;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Category;
use App\Services\Products\PublicProductAttributeFilterService;
use App\Services\Products\PublicProductPriceFilterService;
use App\Support\Api\ProductQueryFilters;
use App\Support\Localization\Locales;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

class CategoryController extends Controller
{
    public function index(CategoryIndexRequest $request): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->with('children')
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->filled('parent_id'), fn ($query) => $query->where('parent_id', $request->integer('parent_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function show(string $slug): CategoryResource
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('children')
            ->firstOrFail();

        return CategoryResource::make($category);
    }

    public function products(
        string $slug,
        ProductIndexRequest $request,
        ProductQueryFilters $filters,
        PublicProductAttributeFilterService $attributeFilters,
        PublicProductPriceFilterService $priceFilters,
    ): AnonymousResourceCollection {
        $category = Category::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        $validated = $request->validated();
        $selectedAttributes = $validated['attribute_filters'] ?? [];
        $locale = Locales::resolveApiRequest($request);
        $scope = $filters->publicQuery()->where('category_id', $category->id);
        $attributeFacetInput = Arr::except($validated, ['price_min', 'price_max', 'attribute_filters']);
        $attributeFacetScope = $filters->apply(clone $scope, $attributeFacetInput);
        $filterMetadata = $attributeFilters->describe($attributeFacetScope, $selectedAttributes, $locale);
        $query = $filters->apply(clone $scope, $attributeFacetInput);
        $attributeFilters->apply($query, $selectedAttributes, $locale);
        $filters->apply($query, Arr::only($validated, ['price_min', 'price_max']));
        $priceScope = $filters->apply(clone $scope, Arr::except($validated, ['price_min', 'price_max']));
        $attributeFilters->apply($priceScope, $selectedAttributes, $locale);
        $filterMetadata += $priceFilters->describe($priceScope, $validated['price_min'] ?? null, $validated['price_max'] ?? null);
        $paginator = $filters
            ->sort($query, $validated['sort'] ?? null)
            ->paginate($filters->perPage($validated))
            ->appends($request->query());

        return ProductCardResource::collection($paginator)->additional($filterMetadata);
    }
}
