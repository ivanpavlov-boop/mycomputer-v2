<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryTreeResource;
use App\Models\Category;
use App\Support\Api\ApiCache;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class NavigationController extends Controller
{
    public function categories(): AnonymousResourceCollection
    {
        $categories = Cache::remember(
            ApiCache::key('navigation-categories'),
            now()->addHour(),
            fn (): Collection => $this->activeCategoryTree(),
        );

        return CategoryTreeResource::collection($categories);
    }

    /**
     * @return Collection<int, Category>
     */
    private function activeCategoryTree(): Collection
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $childrenByParent = $categories->groupBy(
            fn (Category $category): string => $category->parent_id === null
                ? 'root'
                : (string) $category->parent_id,
        );
        $emittedIds = [];

        return $this->attachActiveDescendants(
            $childrenByParent->get('root', collect()),
            $childrenByParent,
            [],
            $emittedIds,
        );
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  Collection<string, Collection<int, Category>>  $childrenByParent
     * @param  array<int, true>  $ancestorIds
     * @param  array<int, true>  $emittedIds
     * @return Collection<int, Category>
     */
    private function attachActiveDescendants(
        Collection $categories,
        Collection $childrenByParent,
        array $ancestorIds,
        array &$emittedIds,
    ): Collection {
        return $categories
            ->filter(function (Category $category) use ($ancestorIds, &$emittedIds): bool {
                if (isset($ancestorIds[$category->id]) || isset($emittedIds[$category->id])) {
                    return false;
                }

                $emittedIds[$category->id] = true;

                return true;
            })
            ->map(function (Category $category) use ($childrenByParent, $ancestorIds, &$emittedIds): Category {
                $nextAncestorIds = $ancestorIds;
                $nextAncestorIds[$category->id] = true;

                $category->setRelation('childrenRecursive', $this->attachActiveDescendants(
                    $childrenByParent->get((string) $category->id, collect()),
                    $childrenByParent,
                    $nextAncestorIds,
                    $emittedIds,
                ));

                return $category;
            })
            ->values();
    }
}
