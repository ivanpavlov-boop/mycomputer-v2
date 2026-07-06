<?php

namespace App\Services\Products;

use App\Models\AttributeValue;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ProductSpecificationQualityService
{
    public function evaluate(Product $product): ProductSpecificationQualityResult
    {
        $assignments = $this->importantAssignments($this->expectedAssignments($product));

        if ($assignments->isEmpty()) {
            return new ProductSpecificationQualityResult(
                status: ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE,
                expectedCount: 0,
                filledCount: 0,
                missingCount: 0,
                percentageComplete: 0,
                expectedAttributes: collect(),
                filledAttributes: collect(),
                missingAttributes: collect(),
            );
        }

        $values = $this->valuesForProduct($product, $assignments->pluck('product_attribute_id')->all());
        $expected = collect();
        $filled = collect();
        $missing = collect();

        foreach ($assignments as $assignment) {
            $attribute = $assignment->attribute;

            if (! $attribute) {
                continue;
            }

            $value = $values->get((int) $attribute->id);
            $row = [
                'assignment' => $assignment,
                'attribute' => $attribute,
                'value' => $value,
                'label' => $this->attributeLabel($attribute),
                'is_required' => $this->assignmentIsRequired($assignment),
            ];

            $expected->push($row);

            if ($this->hasFilledValue($attribute, $value)) {
                $filled->push($row);

                continue;
            }

            $missing->push($row);
        }

        $expectedCount = $expected->count();
        $filledCount = $filled->count();
        $missingCount = $missing->count();
        $percentage = $expectedCount === 0 ? 0 : (int) round(($filledCount / $expectedCount) * 100);

        return new ProductSpecificationQualityResult(
            status: $this->statusFor($missing),
            expectedCount: $expectedCount,
            filledCount: $filledCount,
            missingCount: $missingCount,
            percentageComplete: $percentage,
            expectedAttributes: $expected,
            filledAttributes: $filled,
            missingAttributes: $missing,
        );
    }

    /**
     * @return Collection<int, ProductAttribute>
     */
    public function expectedAttributes(Product $product): Collection
    {
        return $this->importantAssignments($this->expectedAssignments($product))
            ->map(fn (CategoryProductAttribute $assignment): ?ProductAttribute => $assignment->attribute)
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, ProductAttribute>
     */
    public function missingAttributes(Product $product): Collection
    {
        return $this->evaluate($product)
            ->missingAttributes
            ->pluck('attribute')
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, ProductAttribute>
     */
    public function filledAttributes(Product $product): Collection
    {
        return $this->evaluate($product)
            ->filledAttributes
            ->pluck('attribute')
            ->filter()
            ->values();
    }

    public function hasFilledValue(ProductAttribute $attribute, ?ProductAttributeValue $value): bool
    {
        if (! $value) {
            return false;
        }

        return match ($attribute->type) {
            ProductAttribute::TYPE_TEXT => filled(trim((string) ($value->value_text ?? '')))
                || filled(trim((string) ($value->custom_value ?? ''))),
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => $value->value_number !== null
                || is_numeric($value->custom_value),
            ProductAttribute::TYPE_BOOLEAN => $value->value_boolean !== null
                || in_array(strtolower((string) $value->custom_value), ['true', 'false', '1', '0'], true),
            ProductAttribute::TYPE_SELECT => $this->validSelectValue($attribute, $value),
            ProductAttribute::TYPE_MULTISELECT => $this->validMultiselectValue($attribute, $value),
            ProductAttribute::TYPE_JSON => is_array($value->value_json) && $value->value_json !== [],
            default => filled($value->custom_value)
                || filled($value->value_text)
                || $value->value_number !== null
                || $value->value_boolean !== null
                || filled($value->attribute_value_id)
                || (is_array($value->value_json) && $value->value_json !== []),
        };
    }

    /**
     * @return Collection<int, CategoryProductAttribute>
     */
    private function expectedAssignments(Product $product): Collection
    {
        $categoryIds = $this->categoryIdsForProduct($product);

        if ($categoryIds === []) {
            return collect();
        }

        $categoryRank = array_flip($categoryIds);

        return CategoryProductAttribute::query()
            ->with('attribute')
            ->whereIn('category_id', $categoryIds)
            ->whereHas('attribute', fn ($query) => $query->where('is_active', true))
            ->get()
            ->sort(fn (CategoryProductAttribute $left, CategoryProductAttribute $right): int => [
                $categoryRank[(int) $left->category_id] ?? PHP_INT_MAX,
                (int) $left->sort_order,
                (int) $left->id,
            ] <=> [
                $categoryRank[(int) $right->category_id] ?? PHP_INT_MAX,
                (int) $right->sort_order,
                (int) $right->id,
            ])
            ->unique(fn (CategoryProductAttribute $assignment): int => (int) $assignment->product_attribute_id)
            ->values();
    }

    /**
     * @param  Collection<int, CategoryProductAttribute>  $assignments
     * @return Collection<int, CategoryProductAttribute>
     */
    private function importantAssignments(Collection $assignments): Collection
    {
        $required = $assignments
            ->filter(fn (CategoryProductAttribute $assignment): bool => $this->assignmentIsRequired($assignment))
            ->values();

        if ($required->isNotEmpty()) {
            return $required;
        }

        $recommended = $assignments
            ->filter(fn (CategoryProductAttribute $assignment): bool => $this->assignmentIsRecommended($assignment))
            ->values();

        return $recommended->isNotEmpty() ? $recommended : $assignments;
    }

    /**
     * @param  array<int, int|string>  $attributeIds
     * @return Collection<int, ProductAttributeValue>
     */
    private function valuesForProduct(Product $product, array $attributeIds): Collection
    {
        return $product
            ->attributeValues()
            ->with('value')
            ->whereIn('product_attribute_id', array_map('intval', $attributeIds))
            ->get()
            ->keyBy(fn (ProductAttributeValue $value): int => (int) $value->product_attribute_id);
    }

    /**
     * @return array<int, int>
     */
    private function categoryIdsForProduct(Product $product): array
    {
        $product->loadMissing('category.parent');

        $categoryIds = collect([$product->category_id]);
        $category = $product->category;
        $visited = [];
        $guard = 0;

        while ($category !== null && $category->parent_id !== null && $guard < 20) {
            $parentId = (int) $category->parent_id;

            if (isset($visited[$parentId])) {
                break;
            }

            $visited[$parentId] = true;
            $categoryIds->push($parentId);
            $category = $category->parent;
            $guard++;
        }

        return $categoryIds
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function assignmentIsRequired(CategoryProductAttribute $assignment): bool
    {
        return (bool) $assignment->is_required
            || (bool) ($assignment->attribute?->is_required ?? false)
            || (bool) ($assignment->attribute?->is_required_by_default ?? false);
    }

    private function assignmentIsRecommended(CategoryProductAttribute $assignment): bool
    {
        return (bool) $assignment->is_visible_on_product
            || (bool) $assignment->is_filterable
            || (bool) $assignment->is_comparable
            || (bool) ($assignment->attribute?->is_visible_on_product ?? false)
            || (bool) ($assignment->attribute?->is_filterable ?? false)
            || (bool) ($assignment->attribute?->is_comparable ?? false);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $missing
     */
    private function statusFor(Collection $missing): string
    {
        if ($missing->isEmpty()) {
            return ProductSpecificationQualityResult::STATUS_GOOD;
        }

        $hasRequiredMissing = $missing->contains(fn (array $row): bool => (bool) ($row['is_required'] ?? false));

        return $hasRequiredMissing
            ? ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED
            : ProductSpecificationQualityResult::STATUS_NEEDS_DATA;
    }

    private function validSelectValue(ProductAttribute $attribute, ProductAttributeValue $value): bool
    {
        if (! $value->attribute_value_id) {
            return false;
        }

        $option = $value->relationLoaded('value') ? $value->value : null;

        if ($option) {
            return (int) $option->product_attribute_id === (int) $attribute->id;
        }

        return AttributeValue::query()
            ->whereKey($value->attribute_value_id)
            ->where('product_attribute_id', $attribute->id)
            ->exists();
    }

    private function validMultiselectValue(ProductAttribute $attribute, ProductAttributeValue $value): bool
    {
        $optionIds = $this->optionIdsFromJson($value->value_json);

        if ($optionIds === []) {
            return false;
        }

        $validCount = AttributeValue::query()
            ->where('product_attribute_id', $attribute->id)
            ->whereIn('id', $optionIds)
            ->count();

        return $validCount === count($optionIds);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, int>
     */
    private function optionIdsFromJson(?array $json): array
    {
        return collect(Arr::wrap($json['attribute_value_ids'] ?? []))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function attributeLabel(ProductAttribute $attribute): string
    {
        return $attribute->name_bg ?: $attribute->name ?: $attribute->code;
    }
}
