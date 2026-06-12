<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCategoryResource;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlogCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BlogCategoryResource::collection(
            BlogCategory::query()->active()->with('children')->orderBy('sort_order')->get()
        );
    }

    public function show(string $slug): BlogCategoryResource
    {
        return BlogCategoryResource::make(
            BlogCategory::query()->active()->where('slug', $slug)->with('children')->firstOrFail()
        );
    }

    public function posts(string $slug): AnonymousResourceCollection
    {
        $category = BlogCategory::query()->active()->where('slug', $slug)->firstOrFail();

        return BlogPostResource::collection(
            $category->posts()->published()->with(['category', 'tags', 'author'])->latest('published_at')->paginate(12)
        );
    }
}
