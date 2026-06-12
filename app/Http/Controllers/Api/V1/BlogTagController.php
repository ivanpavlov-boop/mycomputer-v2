<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogPostResource;
use App\Http\Resources\BlogTagResource;
use App\Models\BlogTag;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlogTagController extends Controller
{
    public function show(string $slug): AnonymousResourceCollection
    {
        $tag = BlogTag::query()->where('slug', $slug)->firstOrFail();

        return BlogPostResource::collection(
            $tag->posts()->published()->with(['category', 'tags', 'author'])->latest('published_at')->paginate(12)
        );
    }

    public function index(): AnonymousResourceCollection
    {
        return BlogTagResource::collection(BlogTag::query()->orderBy('name')->get());
    }
}
