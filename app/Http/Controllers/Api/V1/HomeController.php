<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogPostResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCardResource;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Product;
use App\Support\Api\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Cache::remember(ApiCache::key('home'), now()->addMinutes(30), fn (): array => [
            'hero_banners' => [
                [
                    'title' => 'mycomputer.bg v2',
                    'subtitle' => 'Computer hardware, laptops, components and accessories.',
                    'image' => null,
                    'url' => '/products',
                ],
            ],
            'featured_categories' => CategoryResource::collection(Category::query()->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->limit(8)->get())->resolve(),
            'featured_products' => ProductCardResource::collection($this->products(fn ($query) => $query->where('featured', true)))->resolve(),
            'new_products' => ProductCardResource::collection($this->products(fn ($query) => $query->where('new_product', true)))->resolve(),
            'bestsellers' => ProductCardResource::collection($this->products(fn ($query) => $query->where('bestseller', true)))->resolve(),
            'promotional_products' => ProductCardResource::collection($this->products(fn ($query) => $query->whereNotNull('promo_price')))->resolve(),
            'latest_articles' => BlogPostResource::collection(BlogPost::query()->published()->with(['category', 'tags', 'author'])->latest('published_at')->limit(3)->get())->resolve(),
        ]);

        return response()->json(['data' => $data]);
    }

    private function products(callable $callback)
    {
        $query = Product::query()->published()->with(['brand', 'category', 'images']);
        $callback($query);

        return $query->latest('published_at')->limit(12)->get();
    }
}
