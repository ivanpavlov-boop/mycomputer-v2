<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogPostDetailResource;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use App\Services\Content\BlogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlogController extends Controller
{
    public function __construct(private readonly BlogService $blog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);

        return BlogPostResource::collection(
            $this->blog->query($request->only(['category', 'tag', 'search']))->paginate($perPage)
        );
    }

    public function show(string $slug): BlogPostDetailResource
    {
        $post = BlogPost::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'category',
                'tags',
                'author',
                'relatedProducts.brand',
                'relatedProducts.category',
                'relatedProducts.images',
                'relatedCategories',
                'relatedBrands',
            ])
            ->firstOrFail();

        $this->blog->incrementViews($post);

        return BlogPostDetailResource::make($post->refresh()->loadMissing(['category', 'tags', 'author']));
    }
}
