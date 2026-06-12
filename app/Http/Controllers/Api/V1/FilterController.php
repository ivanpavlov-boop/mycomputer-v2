<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FilterResource;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Support\Api\ApiCache;
use Illuminate\Support\Facades\Cache;

class FilterController extends Controller
{
    public function category(string $slug, SearchServiceInterface $search): FilterResource
    {
        $filters = Cache::remember(ApiCache::key('category-filters', ['slug' => $slug]), now()->addHour(), function () use ($search, $slug): array {
            return $search->categoryFilters($slug);
        });

        return FilterResource::make($filters);
    }
}
