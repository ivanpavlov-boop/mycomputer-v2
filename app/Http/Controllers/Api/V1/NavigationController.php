<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryTreeResource;
use App\Models\Category;
use App\Support\Api\ApiCache;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class NavigationController extends Controller
{
    public function categories(): AnonymousResourceCollection
    {
        $categories = Cache::remember(ApiCache::key('navigation-categories'), now()->addHour(), fn () => Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['childrenRecursive' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get());

        return CategoryTreeResource::collection($categories);
    }
}
