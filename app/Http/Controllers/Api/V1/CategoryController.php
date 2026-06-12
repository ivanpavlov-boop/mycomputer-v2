<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CategoryIndexRequest;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Category;
use App\Support\Api\ProductQueryFilters;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    public function products(string $slug, ProductIndexRequest $request, ProductQueryFilters $filters): AnonymousResourceCollection
    {
        $category = Category::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        $query = $filters->publicQuery()->where('category_id', $category->id);
        $query = $filters->apply($query, $request->validated());

        return ProductCardResource::collection(
            $filters->sort($query, $request->validated('sort'))->paginate($filters->perPage($request->validated())),
        );
    }
}
