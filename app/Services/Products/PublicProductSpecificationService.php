<?php

namespace App\Services\Products;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Support\Localization\Locales;
use Illuminate\Support\Collection;
use WeakMap;

final class PublicProductSpecificationService
{
    /** @var WeakMap<Product, array<string, array<int, array<string, mixed>>>> */
    private WeakMap $results;

    public function __construct(
        private readonly CategorySpecificationTemplateResolver $templateResolver,
        private readonly ProductSpecificationQualityService $qualityService,
    ) {
        $this->results = new WeakMap;
    }

    /**
     * @return array<int, array{key: string, label: string, position: int, items: array<int, array{key: string, label: string, display_value: string, position: int}>}>
     */
    public function groups(Product $product, ?string $locale = null): array
    {
        $locale = Locales::normalize($locale);
        $cached = $this->results[$product] ?? [];

        if (array_key_exists($locale, $cached)) {
            return $cached[$locale];
        }

        $cached[$locale] = $this->buildGroups($product, $locale);
        $this->results[$product] = $cached;

        return $cached[$locale];
    }

    /**
     * @return array<int, array{key: string, label: string, position: int, items: array<int, array{key: string, label: string, display_value: string, position: int}>}>
     */
    private function buildGroups(Product $product, string $locale): array
    {
        if (! $product->isPubliclyVisible()) {
            return [];
        }

        $template = $this->templateResolver->resolve($product->category_id);
        $assignments = $template->effectiveAssignments;

        if ($assignments->isEmpty()) {
            return [];
        }

        $product->loadMissing(['attributeValues.attribute.group']);

        $attributeIds = $assignments
            ->pluck('product_attribute_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $valuesByAttribute = $product->attributeValues
            ->where('source', ProductAttributeValue::SOURCE_MANUAL)
            ->whereIn('product_attribute_id', $attributeIds)
            ->sortBy(fn (ProductAttributeValue $value): array => [(int) $value->sort_order, (int) $value->id])
            ->groupBy(fn (ProductAttributeValue $value): int => (int) $value->product_attribute_id);
        $optionsByAttribute = AttributeValue::query()
            ->whereIn('product_attribute_id', $attributeIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AttributeValue $option): int => (int) $option->product_attribute_id);

        return $assignments
            ->map(fn (CategoryProductAttribute $assignment): ?array => $this->presentAssignment(
                $assignment,
                $valuesByAttribute->get((int) $assignment->product_attribute_id, collect()),
                $optionsByAttribute->get((int) $assignment->product_attribute_id, collect()),
                $locale,
            ))
            ->filter()
            ->groupBy('group_key')
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $items = $rows
                    ->sort(fn (array $left, array $right): int => [
                        $left['position'],
                        $left['attribute_position'],
                        $left['label'],
                        $left['key'],
                    ] <=> [
                        $right['position'],
                        $right['attribute_position'],
                        $right['label'],
                        $right['key'],
                    ])
                    ->map(fn (array $row): array => [
                        'key' => $row['key'],
                        'label' => $row['label'],
                        'display_value' => $row['display_value'],
                        'position' => $row['position'],
                    ])
                    ->values()
                    ->all();

                return [
                    'key' => $first['group_key'],
                    'label' => $first['group_label'],
                    'position' => $first['group_position'],
                    'items' => $items,
                ];
            })
            ->sort(fn (array $left, array $right): int => [
                $left['position'],
                $left['label'],
                $left['key'],
            ] <=> [
                $right['position'],
                $right['label'],
                $right['key'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     * @param  Collection<int, AttributeValue>  $options
     * @return array<string, mixed>|null
     */
    private function presentAssignment(
        CategoryProductAttribute $assignment,
        Collection $values,
        Collection $options,
        string $locale,
    ): ?array {
        $attribute = $assignment->attribute;

        if (! $attribute
            || ! $attribute->is_active
            || ! $attribute->is_visible_on_product
            || ! $assignment->is_visible_on_product) {
            return null;
        }

        $validOptionIds = $options
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $value = $values->first(
            fn (ProductAttributeValue $value): bool => $this->qualityService->hasFilledValue(
                $attribute,
                $value,
                $validOptionIds,
            ),
        );

        if (! $value) {
            return null;
        }

        $loadedAttribute = $value->attribute;

        if (! $loadedAttribute || ! $loadedAttribute->is_active) {
            return null;
        }

        $group = $loadedAttribute->group;

        if ($loadedAttribute->attribute_group_id !== null && (! $group || ! $group->is_active)) {
            return null;
        }

        $displayValue = $this->formatValue($loadedAttribute, $value, $options, $locale);

        if ($displayValue === null) {
            return null;
        }

        return [
            'group_key' => $group?->slug ?: 'other-specifications',
            'group_label' => $this->groupLabel($group, $locale),
            'group_position' => $group ? (int) $group->sort_order : PHP_INT_MAX,
            'key' => $loadedAttribute->code ?: $loadedAttribute->slug,
            'label' => $loadedAttribute->localizedField('name', $locale, fallbackToPrimary: true)
                ?: $loadedAttribute->name_bg
                ?: $loadedAttribute->name
                ?: $loadedAttribute->code,
            'display_value' => $displayValue,
            'position' => (int) $assignment->sort_order,
            'attribute_position' => (int) $loadedAttribute->sort_order,
        ];
    }

    /**
     * @param  Collection<int, AttributeValue>  $options
     */
    private function formatValue(
        ProductAttribute $attribute,
        ProductAttributeValue $value,
        Collection $options,
        string $locale,
    ): ?string {
        $display = match ($attribute->type) {
            ProductAttribute::TYPE_TEXT => $this->firstFilledText($value->value_text, $value->custom_value),
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => $this->formatNumber(
                $value->value_number ?? $value->custom_value,
            ),
            ProductAttribute::TYPE_BOOLEAN => $this->formatBoolean($value),
            ProductAttribute::TYPE_SELECT => $this->formatSelect($value, $options, $locale),
            ProductAttribute::TYPE_MULTISELECT => $this->formatMultiselect($value, $options, $locale),
            ProductAttribute::TYPE_JSON => $this->formatJson($value->value_json),
            default => $this->firstFilledText($value->custom_value, $value->value_text),
        };

        if ($display === null) {
            return null;
        }

        $unit = trim((string) ($value->unit ?: $attribute->unit));

        if ($unit === '' || str_ends_with(mb_strtolower($display), mb_strtolower($unit))) {
            return $display;
        }

        return $display.' '.$unit;
    }

    private function firstFilledText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function formatNumber(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = trim((string) $value);

        if (str_contains($number, '.') && ! str_contains(strtolower($number), 'e')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return in_array($number, ['-0', '+0'], true) ? '0' : $number;
    }

    private function formatBoolean(ProductAttributeValue $value): ?string
    {
        if ($value->value_boolean !== null) {
            return $value->value_boolean ? 'Да' : 'Не';
        }

        return match (strtolower(trim((string) $value->custom_value))) {
            'true', '1' => 'Да',
            'false', '0' => 'Не',
            default => null,
        };
    }

    /**
     * @param  Collection<int, AttributeValue>  $options
     */
    private function formatSelect(ProductAttributeValue $value, Collection $options, string $locale): ?string
    {
        $option = $options->firstWhere('id', (int) $value->attribute_value_id);

        return $option?->localizedField('value', $locale, fallbackToPrimary: true) ?: $option?->value;
    }

    /**
     * @param  Collection<int, AttributeValue>  $options
     */
    private function formatMultiselect(ProductAttributeValue $value, Collection $options, string $locale): ?string
    {
        $selectedIds = collect($value->value_json['attribute_value_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $labels = $options
            ->filter(fn (AttributeValue $option): bool => $selectedIds->contains((int) $option->id))
            ->map(fn (AttributeValue $option): string => $option->localizedField(
                'value',
                $locale,
                fallbackToPrimary: true,
            ) ?: $option->value)
            ->filter()
            ->values();

        return $labels->isEmpty() ? null : $labels->implode(', ');
    }

    private function formatJson(?array $value): ?string
    {
        if ($value === []) {
            return null;
        }

        $parts = collect($value)
            ->map(function (mixed $item, mixed $key): ?string {
                if (! is_scalar($item) || is_bool($item)) {
                    return null;
                }

                $text = trim((string) $item);

                if ($text === '') {
                    return null;
                }

                return is_string($key) ? trim($key).': '.$text : $text;
            })
            ->filter()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    private function groupLabel(?AttributeGroup $group, string $locale): string
    {
        return $group?->localizedField('name', $locale, fallbackToPrimary: true)
            ?: $group?->name
            ?: 'Други характеристики';
    }
}
