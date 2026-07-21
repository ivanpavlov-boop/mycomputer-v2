<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\CategoryProductAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CategorySpecificationTemplateResolver
{
    /** @var Collection<int, Category>|null */
    private ?Collection $categoriesById = null;

    /** @var Collection<int, Collection<int, CategoryProductAttribute>>|null */
    private ?Collection $assignmentsByCategory = null;

    /** @var array<int, CategorySpecificationTemplateResult> */
    private array $resultsByCategoryId = [];

    public function resolve(Category|int|null $category): CategorySpecificationTemplateResult
    {
        $categoryId = $category instanceof Category ? (int) $category->getKey() : (int) $category;

        if ($categoryId <= 0) {
            return $this->emptyResult();
        }

        if (isset($this->resultsByCategoryId[$categoryId])) {
            return $this->resultsByCategoryId[$categoryId];
        }

        $this->loadSnapshot();
        $resolvedCategory = $this->categoriesById?->get($categoryId);

        if (! $resolvedCategory) {
            return $this->resultsByCategoryId[$categoryId] = $this->emptyResult();
        }

        $categoryChain = $this->categoryChain($resolvedCategory);
        $directAssignments = $this->assignmentsByCategory?->get($categoryId, collect()) ?? collect();
        $effectiveAssignments = collect();
        $claimedAttributeIds = [];
        $sourceCategory = null;

        foreach ($categoryChain as $chainCategory) {
            $assignments = $this->assignmentsByCategory?->get((int) $chainCategory->id, collect()) ?? collect();

            if ($sourceCategory === null && $assignments->isNotEmpty()) {
                $sourceCategory = $chainCategory;
            }

            foreach ($assignments as $assignment) {
                $attributeId = (int) $assignment->product_attribute_id;

                if (isset($claimedAttributeIds[$attributeId])) {
                    continue;
                }

                $claimedAttributeIds[$attributeId] = true;
                $effectiveAssignments->push($assignment);
            }
        }

        $directAttributeIds = $directAssignments
            ->pluck('product_attribute_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $inheritedAssignments = $effectiveAssignments
            ->reject(fn (CategoryProductAttribute $assignment): bool => in_array(
                (int) $assignment->product_attribute_id,
                $directAttributeIds,
                true,
            ))
            ->values();
        $requiredAssignments = $effectiveAssignments
            ->filter(fn (CategoryProductAttribute $assignment): bool => $this->isRequired($assignment))
            ->values();
        $recommendedAssignments = $effectiveAssignments
            ->reject(fn (CategoryProductAttribute $assignment): bool => $this->isRequired($assignment))
            ->filter(fn (CategoryProductAttribute $assignment): bool => $this->isRecommended($assignment))
            ->values();
        $importantAttributeIds = $requiredAssignments
            ->concat($recommendedAssignments)
            ->pluck('product_attribute_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $optionalAssignments = $effectiveAssignments
            ->reject(fn (CategoryProductAttribute $assignment): bool => in_array(
                (int) $assignment->product_attribute_id,
                $importantAttributeIds,
                true,
            ))
            ->values();

        return $this->resultsByCategoryId[$categoryId] = new CategorySpecificationTemplateResult(
            status: match (true) {
                $directAssignments->isNotEmpty() => CategorySpecificationTemplateResult::STATUS_DIRECT_TEMPLATE,
                $inheritedAssignments->isNotEmpty() => CategorySpecificationTemplateResult::STATUS_INHERITED_TEMPLATE,
                default => CategorySpecificationTemplateResult::STATUS_NO_TEMPLATE,
            },
            category: $resolvedCategory,
            sourceCategory: $sourceCategory,
            categoryPath: $categoryChain
                ->reverse()
                ->pluck('name')
                ->map(fn (mixed $name): string => (string) $name)
                ->all(),
            directAssignments: $directAssignments->values(),
            inheritedAssignments: $inheritedAssignments,
            effectiveAssignments: $effectiveAssignments->values(),
            requiredAssignments: $requiredAssignments,
            recommendedAssignments: $recommendedAssignments,
            optionalAssignments: $optionalAssignments,
        );
    }

    /**
     * @return Collection<int, CategorySpecificationTemplateResult>
     */
    public function allResults(): Collection
    {
        $this->loadSnapshot();

        return $this->categoriesById
            ?->map(fn (Category $category): CategorySpecificationTemplateResult => $this->resolve($category))
            ->values() ?? collect();
    }

    public function applyCoverageQuery(Builder $query, ?string $status): Builder
    {
        if (! array_key_exists((string) $status, CategorySpecificationTemplateResult::options())) {
            return $query;
        }

        $categoryIds = $this->allResults()
            ->where('status', $status)
            ->map(fn (CategorySpecificationTemplateResult $result): int => (int) $result->category?->id)
            ->filter()
            ->values()
            ->all();

        return $query->whereKey($categoryIds);
    }

    private function loadSnapshot(): void
    {
        if ($this->categoriesById !== null && $this->assignmentsByCategory !== null) {
            return;
        }

        $this->categoriesById = Category::query()
            ->withTrashed()
            ->get()
            ->keyBy(fn (Category $category): int => (int) $category->id);
        $this->assignmentsByCategory = CategoryProductAttribute::query()
            ->with('attribute')
            ->whereHas('attribute', fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CategoryProductAttribute $assignment): int => (int) $assignment->category_id);
    }

    /**
     * @return Collection<int, Category>
     */
    private function categoryChain(Category $category): Collection
    {
        $chain = collect();
        $visited = [];
        $current = $category;
        $guard = 0;

        while ($current !== null && $guard < 20) {
            $categoryId = (int) $current->id;

            if (isset($visited[$categoryId])) {
                break;
            }

            $visited[$categoryId] = true;
            $chain->push($current);
            $current = $current->parent_id === null
                ? null
                : $this->categoriesById?->get((int) $current->parent_id);
            $guard++;
        }

        return $chain;
    }

    private function isRequired(CategoryProductAttribute $assignment): bool
    {
        return (bool) $assignment->is_required
            || (bool) ($assignment->attribute?->is_required ?? false)
            || (bool) ($assignment->attribute?->is_required_by_default ?? false);
    }

    private function isRecommended(CategoryProductAttribute $assignment): bool
    {
        return (bool) $assignment->is_visible_on_product
            || (bool) $assignment->is_filterable
            || (bool) $assignment->is_comparable
            || (bool) ($assignment->attribute?->is_visible_on_product ?? false)
            || (bool) ($assignment->attribute?->is_filterable ?? false)
            || (bool) ($assignment->attribute?->is_comparable ?? false);
    }

    private function emptyResult(): CategorySpecificationTemplateResult
    {
        return new CategorySpecificationTemplateResult(
            status: CategorySpecificationTemplateResult::STATUS_NO_TEMPLATE,
            category: null,
            sourceCategory: null,
            categoryPath: [],
            directAssignments: collect(),
            inheritedAssignments: collect(),
            effectiveAssignments: collect(),
            requiredAssignments: collect(),
            recommendedAssignments: collect(),
            optionalAssignments: collect(),
        );
    }
}
