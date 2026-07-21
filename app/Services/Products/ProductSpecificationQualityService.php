<?php

namespace App\Services\Products;

use App\Models\AttributeValue;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use WeakMap;

final class ProductSpecificationQualityService
{
    /** @var WeakMap<Product, ProductSpecificationQualityResult> */
    private WeakMap $results;

    /** @var Collection<int, Collection<int, int>>|null */
    private ?Collection $optionIdsByAttribute = null;

    public function __construct(
        private readonly CategorySpecificationTemplateResolver $templateResolver,
    ) {
        $this->results = new WeakMap;
    }

    public function evaluate(Product $product): ProductSpecificationQualityResult
    {
        return $this->results[$product] ??= $this->evaluateFresh($product);
    }

    public function applyStateQuery(Builder $query, ?string $status): Builder
    {
        if (! array_key_exists((string) $status, ProductSpecificationQualityResult::options())) {
            return $query;
        }

        $groups = $this->assignmentGroups();
        $categoryColumn = $query->getModel()->qualifyColumn('category_id');

        return $query->where(function (Builder $query) use ($categoryColumn, $groups, $status): void {
            if ($status === ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE) {
                $knownCategoryIds = $this->templateResolver->allResults()
                    ->map(fn (CategorySpecificationTemplateResult $result): int => (int) $result->category?->id)
                    ->filter()
                    ->values()
                    ->all();
                $noTemplateIds = $this->templateResolver->allResults()
                    ->filter(fn (CategorySpecificationTemplateResult $result): bool => $result->qualityAssignments()->isEmpty())
                    ->map(fn (CategorySpecificationTemplateResult $result): int => (int) $result->category?->id)
                    ->filter()
                    ->values()
                    ->all();

                $query->whereNull($categoryColumn)
                    ->when($noTemplateIds !== [], fn (Builder $query): Builder => $query->orWhereIn($categoryColumn, $noTemplateIds))
                    ->when($knownCategoryIds !== [], fn (Builder $query): Builder => $query->orWhereNotIn($categoryColumn, $knownCategoryIds));

                return;
            }

            $hasMatchingGroup = false;

            foreach ($groups as $group) {
                if (! $this->groupCanMatchStatus($group, $status)) {
                    continue;
                }

                $hasMatchingGroup = true;

                $query->orWhere(function (Builder $query) use ($categoryColumn, $group, $status): void {
                    $query->whereIn($categoryColumn, $group['category_ids']);
                    $this->applyGroupStatus($query, $group, $status);
                });
            }

            if (! $hasMatchingGroup) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @return array{good: int, needs_data: int, missing_required: int, no_category_template: int}
     */
    public function countsFor(Builder $query): array
    {
        return collect(array_keys(ProductSpecificationQualityResult::options()))
            ->mapWithKeys(fn (string $status): array => [
                $status => $this->applyStateQuery(clone $query, $status)->count(),
            ])
            ->all();
    }

    /**
     * @return Collection<int, ProductAttribute>
     */
    public function expectedAttributes(Product $product): Collection
    {
        return $this->templateResolver
            ->resolve($product->category_id)
            ->qualityAssignments()
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

    /**
     * @param  Collection<int, int>|null  $validOptionIds
     */
    public function hasFilledValue(
        ProductAttribute $attribute,
        ?ProductAttributeValue $value,
        ?Collection $validOptionIds = null,
    ): bool {
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
            ProductAttribute::TYPE_SELECT => $this->validSelectValue($attribute, $value, $validOptionIds),
            ProductAttribute::TYPE_MULTISELECT => $this->validMultiselectValue($attribute, $value, $validOptionIds),
            ProductAttribute::TYPE_JSON => is_array($value->value_json) && $value->value_json !== [],
            default => filled($value->custom_value)
                || filled($value->value_text)
                || $value->value_number !== null
                || $value->value_boolean !== null
                || filled($value->attribute_value_id)
                || (is_array($value->value_json) && $value->value_json !== []),
        };
    }

    private function evaluateFresh(Product $product): ProductSpecificationQualityResult
    {
        $template = $this->templateResolver->resolve($product->category_id);
        $assignments = $template->qualityAssignments();

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
                templateCoverage: $template,
                requiredAttributes: collect(),
                recommendedAttributes: collect(),
                invalidRequiredAttributes: collect(),
                invalidRecommendedAttributes: collect(),
                reason: blank($product->category_id) ? 'missing_category' : 'missing_template',
            );
        }

        $attributeIds = $assignments->pluck('product_attribute_id')->map(fn (mixed $id): int => (int) $id)->all();
        $values = $this->valuesForProduct($product, $attributeIds);
        $requiredAttributeIds = $template->requiredAssignments
            ->pluck('product_attribute_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $expected = collect();
        $filled = collect();
        $missing = collect();
        $invalidRequired = collect();
        $invalidRecommended = collect();

        foreach ($assignments as $assignment) {
            $attribute = $assignment->attribute;

            if (! $attribute) {
                continue;
            }

            $validOptionIds = $this->optionIdsForAttribute((int) $attribute->id);
            $candidateValues = $values->get((int) $attribute->id, collect());
            $filledValue = $candidateValues->first(
                fn (ProductAttributeValue $value): bool => $this->hasFilledValue($attribute, $value, $validOptionIds),
            );
            $value = $filledValue ?: $candidateValues->first();
            $isRequired = in_array((int) $attribute->id, $requiredAttributeIds, true);
            $valueState = $filledValue
                ? 'filled'
                : $this->unfilledValueState($attribute, $value, $validOptionIds);
            $row = [
                'assignment' => $assignment,
                'attribute' => $attribute,
                'value' => $value,
                'label' => $this->attributeLabel($attribute),
                'is_required' => $isRequired,
                'is_recommended' => ! $isRequired,
                'value_state' => $valueState,
            ];

            $expected->push($row);

            if ($valueState === 'filled') {
                $filled->push($row);

                continue;
            }

            $missing->push($row);

            if ($valueState === 'invalid') {
                ($isRequired ? $invalidRequired : $invalidRecommended)->push($row);
            }
        }

        $required = $expected->where('is_required', true)->values();
        $recommended = $expected->where('is_required', false)->values();
        $expectedCount = $expected->count();
        $filledCount = $filled->count();
        $missingCount = $missing->count();
        $percentage = $expectedCount === 0 ? 0 : (int) round(($filledCount / $expectedCount) * 100);

        return new ProductSpecificationQualityResult(
            status: match (true) {
                $required->contains(fn (array $row): bool => $row['value_state'] !== 'filled') => ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED,
                $recommended->contains(fn (array $row): bool => $row['value_state'] !== 'filled') => ProductSpecificationQualityResult::STATUS_NEEDS_DATA,
                default => ProductSpecificationQualityResult::STATUS_GOOD,
            },
            expectedCount: $expectedCount,
            filledCount: $filledCount,
            missingCount: $missingCount,
            percentageComplete: $percentage,
            expectedAttributes: $expected,
            filledAttributes: $filled,
            missingAttributes: $missing,
            templateCoverage: $template,
            requiredAttributes: $required,
            recommendedAttributes: $recommended,
            invalidRequiredAttributes: $invalidRequired,
            invalidRecommendedAttributes: $invalidRecommended,
        );
    }

    /**
     * @param  array<int, int>  $attributeIds
     * @return Collection<int, Collection<int, ProductAttributeValue>>
     */
    private function valuesForProduct(Product $product, array $attributeIds): Collection
    {
        $values = match (true) {
            $product->relationLoaded('attributeValues') => $product->attributeValues,
            $product->relationLoaded('attributes') => $product->attributes,
            default => $product->attributeValues()->whereIn('product_attribute_id', $attributeIds)->get(),
        };

        return $values
            ->whereIn('product_attribute_id', $attributeIds)
            ->groupBy(fn (ProductAttributeValue $value): int => (int) $value->product_attribute_id);
    }

    /**
     * @param  Collection<int, int>|null  $validOptionIds
     */
    private function validSelectValue(
        ProductAttribute $attribute,
        ProductAttributeValue $value,
        ?Collection $validOptionIds = null,
    ): bool {
        if (! $value->attribute_value_id) {
            return false;
        }

        if ($validOptionIds !== null) {
            return $validOptionIds->contains((int) $value->attribute_value_id);
        }

        if ($value->relationLoaded('value')) {
            return $value->value !== null
                && (int) $value->value->product_attribute_id === (int) $attribute->id;
        }

        return AttributeValue::query()
            ->whereKey($value->attribute_value_id)
            ->where('product_attribute_id', $attribute->id)
            ->exists();
    }

    /**
     * @param  Collection<int, int>|null  $validOptionIds
     */
    private function validMultiselectValue(
        ProductAttribute $attribute,
        ProductAttributeValue $value,
        ?Collection $validOptionIds = null,
    ): bool {
        $optionIds = $this->optionIdsFromJson($value->value_json);

        if ($optionIds === []) {
            return false;
        }

        if ($validOptionIds !== null) {
            return collect($optionIds)->every(fn (int $optionId): bool => $validOptionIds->contains($optionId));
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

    /**
     * @param  Collection<int, int>  $validOptionIds
     */
    private function unfilledValueState(
        ProductAttribute $attribute,
        ?ProductAttributeValue $value,
        Collection $validOptionIds,
    ): string {
        if (! $value) {
            return 'missing';
        }

        $hasCandidate = match ($attribute->type) {
            ProductAttribute::TYPE_TEXT => filled(trim((string) ($value->value_text ?? '')))
                || filled(trim((string) ($value->custom_value ?? ''))),
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => filled(trim((string) ($value->custom_value ?? ''))),
            ProductAttribute::TYPE_BOOLEAN => filled((string) $value->custom_value),
            ProductAttribute::TYPE_SELECT => filled($value->attribute_value_id),
            ProductAttribute::TYPE_MULTISELECT => $this->optionIdsFromJson($value->value_json) !== [],
            ProductAttribute::TYPE_JSON => is_array($value->value_json) && $value->value_json !== [],
            default => filled($value->custom_value)
                || filled($value->value_text)
                || $value->value_number !== null
                || $value->value_boolean !== null
                || filled($value->attribute_value_id)
                || (is_array($value->value_json) && $value->value_json !== []),
        };

        return $hasCandidate && ! $this->hasFilledValue($attribute, $value, $validOptionIds)
            ? 'invalid'
            : 'missing';
    }

    /**
     * @return Collection<int, int>
     */
    private function optionIdsForAttribute(int $attributeId): Collection
    {
        $this->optionIdsByAttribute ??= AttributeValue::query()
            ->get(['id', 'product_attribute_id'])
            ->groupBy(fn (AttributeValue $value): int => (int) $value->product_attribute_id)
            ->map(fn (Collection $values): Collection => $values
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values());

        return $this->optionIdsByAttribute->get($attributeId, collect());
    }

    /**
     * @return Collection<int, array{category_ids: array<int, int>, required: Collection<int, CategoryProductAttribute>, recommended: Collection<int, CategoryProductAttribute>}>
     */
    private function assignmentGroups(): Collection
    {
        return $this->templateResolver->allResults()
            ->filter(fn (CategorySpecificationTemplateResult $result): bool => $result->qualityAssignments()->isNotEmpty())
            ->groupBy(function (CategorySpecificationTemplateResult $result): string {
                $requiredIds = $result->requiredAssignments->pluck('product_attribute_id')->map('intval')->sort()->implode(',');
                $recommendedIds = $result->qualityAssignments()
                    ->reject(fn (CategoryProductAttribute $assignment): bool => $result->requiredAssignments
                        ->contains('product_attribute_id', $assignment->product_attribute_id))
                    ->pluck('product_attribute_id')
                    ->map('intval')
                    ->sort()
                    ->implode(',');

                return $requiredIds.'|'.$recommendedIds;
            })
            ->map(function (Collection $results): array {
                /** @var CategorySpecificationTemplateResult $first */
                $first = $results->first();
                $requiredIds = $first->requiredAssignments->pluck('product_attribute_id')->map('intval')->all();

                return [
                    'category_ids' => $results
                        ->map(fn (CategorySpecificationTemplateResult $result): int => (int) $result->category?->id)
                        ->filter()
                        ->values()
                        ->all(),
                    'required' => $first->qualityAssignments()
                        ->filter(fn (CategoryProductAttribute $assignment): bool => in_array(
                            (int) $assignment->product_attribute_id,
                            $requiredIds,
                            true,
                        ))
                        ->values(),
                    'recommended' => $first->qualityAssignments()
                        ->reject(fn (CategoryProductAttribute $assignment): bool => in_array(
                            (int) $assignment->product_attribute_id,
                            $requiredIds,
                            true,
                        ))
                        ->values(),
                ];
            })
            ->values();
    }

    /**
     * @param  array{category_ids: array<int, int>, required: Collection<int, CategoryProductAttribute>, recommended: Collection<int, CategoryProductAttribute>}  $group
     */
    private function groupCanMatchStatus(array $group, string $status): bool
    {
        return match ($status) {
            ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED => $group['required']->isNotEmpty(),
            ProductSpecificationQualityResult::STATUS_NEEDS_DATA => $group['recommended']->isNotEmpty(),
            ProductSpecificationQualityResult::STATUS_GOOD => true,
            default => false,
        };
    }

    /**
     * @param  array{category_ids: array<int, int>, required: Collection<int, CategoryProductAttribute>, recommended: Collection<int, CategoryProductAttribute>}  $group
     */
    private function applyGroupStatus(Builder $query, array $group, string $status): void
    {
        if ($status === ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED) {
            $this->whereAnyAssignmentMissing($query, $group['required']);

            return;
        }

        $this->whereAllAssignmentsFilled($query, $group['required']);

        if ($status === ProductSpecificationQualityResult::STATUS_NEEDS_DATA) {
            $this->whereAnyAssignmentMissing($query, $group['recommended']);

            return;
        }

        $this->whereAllAssignmentsFilled($query, $group['recommended']);
    }

    /**
     * @param  Collection<int, CategoryProductAttribute>  $assignments
     */
    private function whereAllAssignmentsFilled(Builder $query, Collection $assignments): void
    {
        foreach ($assignments as $assignment) {
            $query->whereHas('attributeValues', function (Builder $query) use ($assignment): void {
                $this->whereValueFilled($query, $assignment);
            });
        }
    }

    /**
     * @param  Collection<int, CategoryProductAttribute>  $assignments
     */
    private function whereAnyAssignmentMissing(Builder $query, Collection $assignments): void
    {
        $query->where(function (Builder $query) use ($assignments): void {
            foreach ($assignments as $assignment) {
                $query->orWhereDoesntHave('attributeValues', function (Builder $query) use ($assignment): void {
                    $this->whereValueFilled($query, $assignment);
                });
            }
        });
    }

    private function whereValueFilled(Builder $query, CategoryProductAttribute $assignment): void
    {
        $attribute = $assignment->attribute;

        if (! $attribute) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('product_attribute_values.product_attribute_id', $attribute->id);

        match ($attribute->type) {
            ProductAttribute::TYPE_TEXT => $query->where(function (Builder $query): void {
                $query->whereRaw("TRIM(COALESCE(product_attribute_values.value_text, '')) <> ''")
                    ->orWhereRaw("TRIM(COALESCE(product_attribute_values.custom_value, '')) <> ''");
            }),
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => $query->where(function (Builder $query): void {
                $query->whereNotNull('product_attribute_values.value_number')
                    ->orWhereRaw(
                        "TRIM(COALESCE(product_attribute_values.custom_value, '')) REGEXP ?",
                        ['^[+-]?([0-9]+(\\.[0-9]*)?|\\.[0-9]+)([eE][+-]?[0-9]+)?$'],
                    );
            }),
            ProductAttribute::TYPE_BOOLEAN => $query->where(function (Builder $query): void {
                $query->whereNotNull('product_attribute_values.value_boolean')
                    ->orWhereRaw('LOWER(product_attribute_values.custom_value) IN (?, ?, ?, ?)', ['true', 'false', '1', '0']);
            }),
            ProductAttribute::TYPE_SELECT => $this->whereSelectFilled($query, (int) $attribute->id),
            ProductAttribute::TYPE_MULTISELECT => $this->whereMultiselectFilled($query, (int) $attribute->id),
            ProductAttribute::TYPE_JSON => $this->whereJsonFilled($query),
            default => $query->where(function (Builder $query): void {
                $query->whereRaw("TRIM(COALESCE(product_attribute_values.custom_value, '')) <> ''")
                    ->orWhereRaw("TRIM(COALESCE(product_attribute_values.value_text, '')) <> ''")
                    ->orWhereNotNull('product_attribute_values.value_number')
                    ->orWhereNotNull('product_attribute_values.value_boolean')
                    ->orWhereNotNull('product_attribute_values.attribute_value_id')
                    ->orWhereNotNull('product_attribute_values.value_json');
            }),
        };
    }

    private function whereSelectFilled(Builder $query, int $attributeId): void
    {
        $optionIds = $this->optionIdsForAttribute($attributeId)->all();

        if ($optionIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('product_attribute_values.attribute_value_id', $optionIds);
    }

    private function whereMultiselectFilled(Builder $query, int $attributeId): void
    {
        $optionIds = $this->optionIdsForAttribute($attributeId)->all();

        if ($optionIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($optionIds), '?'));
        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $query
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(product_attribute_values.value_json, '$.attribute_value_ids')) > 0")
                ->whereRaw(
                    "NOT EXISTS (SELECT 1 FROM JSON_TABLE(JSON_EXTRACT(product_attribute_values.value_json, '$.attribute_value_ids'), '$[*]' COLUMNS(option_id BIGINT PATH '$')) AS selected_options WHERE selected_options.option_id NOT IN ({$placeholders}))",
                    $optionIds,
                );

            return;
        }

        $query
            ->whereRaw("json_array_length(json_extract(product_attribute_values.value_json, '$.attribute_value_ids')) > 0")
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM json_each(json_extract(product_attribute_values.value_json, '$.attribute_value_ids')) AS selected_options WHERE CAST(selected_options.value AS INTEGER) NOT IN ({$placeholders}))",
                $optionIds,
            );
    }

    private function whereJsonFilled(Builder $query): void
    {
        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('JSON_LENGTH(product_attribute_values.value_json) > 0');

            return;
        }

        $query->whereRaw('EXISTS (SELECT 1 FROM json_each(product_attribute_values.value_json))');
    }

    private function attributeLabel(ProductAttribute $attribute): string
    {
        return $attribute->name_bg ?: $attribute->name ?: $attribute->code;
    }
}
