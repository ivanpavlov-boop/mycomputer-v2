<?php

namespace App\Services\Products;

use App\Enums\CategoryAttributeFilterControl;
use App\Models\AttributeValue;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Support\Localization\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class PublicProductAttributeFilterService
{
    public const MAX_FILTERS = 12;

    public const MAX_OPTIONS_PER_FILTER = 20;

    /** @var array<int, string> */
    private const SUPPORTED_TYPES = [
        ProductAttribute::TYPE_SELECT,
        ProductAttribute::TYPE_MULTISELECT,
        ProductAttribute::TYPE_BOOLEAN,
        ProductAttribute::TYPE_NUMBER,
        ProductAttribute::TYPE_DECIMAL,
    ];

    /** @var array<string, array{public: array<int, array<string, mixed>>, internal: array<string, array<string, mixed>>}> */
    private array $states = [];

    public function __construct(
        private readonly CategorySpecificationTemplateResolver $templateResolver,
    ) {}

    /**
     * @param  array<string, array<int|string, mixed>>  $selected
     * @return array{filters: array<int, array<string, mixed>>, active_filters: array<int, array<string, mixed>>}
     */
    public function describe(Builder $scope, array $selected, ?string $locale = null): array
    {
        $locale = Locales::normalize($locale);
        $state = $this->state($scope, $locale);
        $normalized = $this->validateSelections($selected, $state['internal']);

        return [
            'filters' => $state['public'],
            'active_filters' => $this->activeFilters($normalized, $state['internal']),
        ];
    }

    /**
     * @param  array<string, array<int|string, mixed>>  $selected
     */
    public function apply(Builder $query, array $selected, ?string $locale = null): Builder
    {
        if ($selected === []) {
            return $query;
        }

        $state = $this->state($query, Locales::normalize($locale));
        $normalized = $this->validateSelections($selected, $state['internal']);

        foreach ($normalized as $key => $selection) {
            $filter = $state['internal'][$key];
            $attribute = $filter['attribute'];

            $query->whereIn('products.category_id', $filter['category_ids']);
            $query->whereHas('attributeValues', function (Builder $values) use ($attribute, $filter, $selection): void {
                $values
                    ->where('product_attribute_values.product_attribute_id', $attribute->id)
                    ->where('product_attribute_values.source', ProductAttributeValue::SOURCE_MANUAL)
                    ->where('product_attribute_values.is_filterable', true);

                match ($attribute->type) {
                    ProductAttribute::TYPE_SELECT => $values->whereIn(
                        'product_attribute_values.attribute_value_id',
                        collect($selection['values'])
                            ->map(fn (string $optionKey): int => (int) $filter['options'][$optionKey]['id'])
                            ->all(),
                    ),
                    ProductAttribute::TYPE_MULTISELECT => $this->applyMultiselect(
                        $values,
                        collect($selection['values'])
                            ->map(fn (string $optionKey): int => (int) $filter['options'][$optionKey]['id'])
                            ->all(),
                        collect($filter['options'])->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    ),
                    ProductAttribute::TYPE_BOOLEAN => $values->whereIn(
                        'product_attribute_values.value_boolean',
                        collect($selection['values'])
                            ->map(fn (string $value): bool => $value === 'yes')
                            ->all(),
                    ),
                    ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => $this->applyNumericRange(
                        $values,
                        $selection['min'] ?? null,
                        $selection['max'] ?? null,
                    ),
                    default => null,
                };
            });
        }

        return $query;
    }

    /**
     * @return array{public: array<int, array<string, mixed>>, internal: array<string, array<string, mixed>>}
     */
    private function state(Builder $scope, string $locale): array
    {
        $cacheKey = $this->scopeKey($scope).'|'.$locale;

        if (isset($this->states[$cacheKey])) {
            return $this->states[$cacheKey];
        }

        $definitions = $this->definitions($scope);

        if ($definitions->isEmpty()) {
            return $this->states[$cacheKey] = ['public' => [], 'internal' => []];
        }

        $attributeIds = $definitions->pluck('attribute.id')->map(fn (mixed $id): int => (int) $id)->all();
        $optionsByAttribute = AttributeValue::query()
            ->whereIn('product_attribute_id', $attributeIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AttributeValue $option): int => (int) $option->product_attribute_id);
        $rowsByAttribute = $this->facetValueRows($scope, $definitions)
            ->groupBy(fn (ProductAttributeValue $value): int => (int) $value->product_attribute_id);
        $public = collect();
        $internal = [];

        foreach ($definitions as $definition) {
            /** @var ProductAttribute $attribute */
            $attribute = $definition['attribute'];
            $rows = $rowsByAttribute->get((int) $attribute->id, collect());
            $options = $optionsByAttribute->get((int) $attribute->id, collect());
            $filter = $this->buildFilter($attribute, $definition, $rows, $options, $locale);

            if ($filter === null) {
                continue;
            }

            if ($filter['public'] !== null) {
                $public->push($filter['public']);
            }

            $internal[$filter['internal']['key']] = $filter['internal'];
        }

        return $this->states[$cacheKey] = [
            'public' => $public->values()->all(),
            'internal' => $internal,
        ];
    }

    /**
     * @return Collection<int, array{attribute: ProductAttribute, category_ids: array<int, int>, position: int, control: string}>
     */
    private function definitions(Builder $scope): Collection
    {
        $categoryIds = (clone $scope)
            ->reorder()
            ->select('products.category_id')
            ->distinct()
            ->pluck('category_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $candidates = collect();

        foreach ($categoryIds as $categoryId) {
            foreach ($this->templateResolver->resolve($categoryId)->effectiveAssignments as $assignment) {
                $attribute = $assignment->attribute;

                if (! $attribute
                    || ! $attribute->is_active
                    || ! $attribute->is_filterable
                    || ! $attribute->is_visible_on_product
                    || ! $assignment->is_filterable
                    || ! $assignment->is_visible_on_product
                    || ! in_array($attribute->type, self::SUPPORTED_TYPES, true)) {
                    continue;
                }

                $key = $this->attributeKey($attribute);

                if ($key === null) {
                    continue;
                }

                $candidate = $candidates->get($key, [
                    'attribute_id' => (int) $attribute->id,
                    'category_ids' => [],
                    'position' => (int) $assignment->sort_order,
                    'controls' => [],
                ]);
                $resolvedControl = $assignment->resolvedFilterControl();

                $candidate['category_ids'][] = $categoryId;
                $candidate['category_ids'] = array_values(array_unique($candidate['category_ids']));
                $candidate['position'] = min($candidate['position'], (int) $assignment->sort_order);
                $candidate['controls'][] = $resolvedControl?->value ?? 'incompatible';
                $candidate['controls'] = array_values(array_unique($candidate['controls']));
                $candidates->put($key, $candidate);
            }
        }

        $attributes = ProductAttribute::query()
            ->with('group')
            ->whereIn('id', $candidates->pluck('attribute_id'))
            ->get()
            ->keyBy('id');

        return $candidates
            ->map(function (array $candidate) use ($attributes): ?array {
                /** @var ProductAttribute|null $attribute */
                $attribute = $attributes->get($candidate['attribute_id']);

                if (! $attribute || ($attribute->group && ! $attribute->group->is_active)) {
                    return null;
                }

                $control = $this->commonControl($candidate['controls']);

                if ($control === null) {
                    return null;
                }

                return [
                    'attribute' => $attribute,
                    'category_ids' => $candidate['category_ids'],
                    'position' => $candidate['position'],
                    'control' => $control,
                ];
            })
            ->filter()
            ->sort(fn (array $left, array $right): int => [
                $left['position'],
                (int) $left['attribute']->sort_order,
                $this->attributeKey($left['attribute']),
            ] <=> [
                $right['position'],
                (int) $right['attribute']->sort_order,
                $this->attributeKey($right['attribute']),
            ])
            ->take(self::MAX_FILTERS)
            ->values();
    }

    /**
     * @param  Collection<int, array{attribute: ProductAttribute, category_ids: array<int, int>, position: int, control: string}>  $definitions
     * @return Collection<int, ProductAttributeValue>
     */
    private function facetValueRows(Builder $scope, Collection $definitions): Collection
    {
        $productIds = (clone $scope)->reorder()->select('products.id');

        return ProductAttributeValue::query()
            ->join('products as facet_products', 'facet_products.id', '=', 'product_attribute_values.product_id')
            ->whereIn('product_attribute_values.product_id', $productIds)
            ->where('product_attribute_values.source', ProductAttributeValue::SOURCE_MANUAL)
            ->where('product_attribute_values.is_filterable', true)
            ->where(function (Builder $query) use ($definitions): void {
                foreach ($definitions as $definition) {
                    $query->orWhere(function (Builder $pair) use ($definition): void {
                        $pair
                            ->where('product_attribute_values.product_attribute_id', $definition['attribute']->id)
                            ->whereIn('facet_products.category_id', $definition['category_ids']);
                    });
                }
            })
            ->select([
                'product_attribute_values.product_attribute_id',
                'product_attribute_values.attribute_value_id',
                'product_attribute_values.value_number',
                'product_attribute_values.value_boolean',
                'product_attribute_values.value_json',
            ])
            ->distinct()
            ->get();
    }

    /**
     * @param  array{attribute: ProductAttribute, category_ids: array<int, int>, position: int, control: string}  $definition
     * @param  Collection<int, ProductAttributeValue>  $rows
     * @param  Collection<int, AttributeValue>  $options
     * @return array{public: array<string, mixed>|null, internal: array<string, mixed>}|null
     */
    private function buildFilter(
        ProductAttribute $attribute,
        array $definition,
        Collection $rows,
        Collection $options,
        string $locale,
    ): ?array {
        $key = $this->attributeKey($attribute);
        $label = $attribute->localizedField('name', $locale, fallbackToPrimary: true)
            ?: $attribute->name_bg
            ?: $attribute->name
            ?: $key;
        $base = [
            'key' => $key,
            'label' => $label,
            'position' => $definition['position'],
            'control' => $definition['control'],
        ];
        $internalBase = [
            'attribute' => $attribute,
            'category_ids' => $definition['category_ids'],
            'label' => $label,
            'unit' => filled($attribute->unit) ? (string) $attribute->unit : null,
            'control' => $definition['control'],
        ];

        if (in_array($attribute->type, [ProductAttribute::TYPE_SELECT, ProductAttribute::TYPE_MULTISELECT], true)) {
            $validOptions = $options->keyBy(fn (AttributeValue $option): int => (int) $option->id);
            $usedOptionIds = $attribute->type === ProductAttribute::TYPE_SELECT
                ? $rows->pluck('attribute_value_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()
                : $rows
                    ->flatMap(function (ProductAttributeValue $row) use ($validOptions): array {
                        $ids = $this->multiselectIds($row->value_json);

                        return $ids !== [] && collect($ids)->every(fn (int $id): bool => $validOptions->has($id))
                            ? $ids
                            : [];
                    })
                    ->unique();
            $available = $options
                ->filter(fn (AttributeValue $option): bool => $usedOptionIds->contains((int) $option->id))
                ->take(self::MAX_OPTIONS_PER_FILTER)
                ->map(function (AttributeValue $option) use ($locale): array {
                    return [
                        'id' => (int) $option->id,
                        'key' => (string) $option->slug,
                        'label' => $option->localizedField('value', $locale, fallbackToPrimary: true)
                            ?: $option->value
                            ?: $option->slug,
                    ];
                })
                ->filter(fn (array $option): bool => $option['key'] !== '')
                ->values();

            if ($available->isEmpty()) {
                return null;
            }

            $publicOptions = $available->map(fn (array $option): array => [
                'key' => $option['key'],
                'label' => $option['label'],
            ])->all();

            return [
                'public' => $available->count() >= 2
                    ? $base + [
                        'type' => $attribute->type,
                        'options' => $publicOptions,
                    ]
                    : null,
                'internal' => $internalBase + [
                    'key' => $key,
                    'type' => $attribute->type,
                    'options' => $available->keyBy('key')->all(),
                ],
            ];
        }

        if ($attribute->type === ProductAttribute::TYPE_BOOLEAN) {
            $states = $rows
                ->filter(fn (ProductAttributeValue $row): bool => $row->value_boolean !== null)
                ->map(fn (ProductAttributeValue $row): string => $row->value_boolean ? 'yes' : 'no')
                ->unique()
                ->values();

            if ($states->isEmpty()) {
                return null;
            }

            $options = collect([
                'yes' => ['key' => 'yes', 'label' => 'Да'],
                'no' => ['key' => 'no', 'label' => 'Не'],
            ])->only($states)->all();

            return [
                'public' => count($options) === 2
                    ? $base + ['type' => 'boolean', 'options' => array_values($options)]
                    : null,
                'internal' => $internalBase + ['key' => $key, 'type' => 'boolean', 'options' => $options],
            ];
        }

        $numbers = $rows
            ->pluck('value_number')
            ->filter(fn (mixed $value): bool => $value !== null && is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value);

        if ($numbers->isEmpty()) {
            return null;
        }

        $minimum = $this->numericValue($numbers->min());
        $maximum = $this->numericValue($numbers->max());
        $step = $attribute->type === ProductAttribute::TYPE_NUMBER ? 1 : 0.01;

        return [
            'public' => $minimum < $maximum
                ? $base + [
                    'type' => 'number_range',
                    'unit' => $internalBase['unit'],
                    'min' => $minimum,
                    'max' => $maximum,
                    'step' => $step,
                ]
                : null,
            'internal' => $internalBase + [
                'key' => $key,
                'type' => 'number_range',
                'min' => $minimum,
                'max' => $maximum,
                'step' => $step,
                'options' => [],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $controls
     */
    private function commonControl(array $controls): ?string
    {
        $controls = array_values(array_unique($controls));

        if (in_array('incompatible', $controls, true)) {
            return null;
        }

        if (count($controls) === 1) {
            return $controls[0];
        }

        sort($controls);
        $numericControls = [
            CategoryAttributeFilterControl::MinMax->value,
            CategoryAttributeFilterControl::RangeSlider->value,
        ];
        sort($numericControls);

        return $controls === $numericControls
            ? CategoryAttributeFilterControl::MinMax->value
            : null;
    }

    /**
     * @param  array<string, array<int|string, mixed>>  $selected
     * @param  array<string, array<string, mixed>>  $filters
     * @return array<string, array<string, mixed>>
     */
    private function validateSelections(array $selected, array $filters): array
    {
        if (count($selected) > self::MAX_FILTERS) {
            throw ValidationException::withMessages([
                'attribute_filters' => 'Избрани са твърде много филтри.',
            ]);
        }

        $normalized = [];

        foreach ($selected as $key => $selection) {
            if (! is_string($key) || ! isset($filters[$key]) || ! is_array($selection)) {
                throw ValidationException::withMessages([
                    'attribute_filters' => 'Избран е невалиден филтър.',
                ]);
            }

            $filter = $filters[$key];

            if ($filter['type'] === 'number_range') {
                $unknownOperators = array_diff(array_keys($selection), ['min', 'max']);
                $min = $this->validatedNumber($selection['min'] ?? null, "attribute_filters.$key.min");
                $max = $this->validatedNumber($selection['max'] ?? null, "attribute_filters.$key.max");

                if ($unknownOperators !== [] || ($min === null && $max === null) || ($min !== null && $max !== null && $min > $max)) {
                    throw ValidationException::withMessages([
                        "attribute_filters.$key" => 'Невалиден числов диапазон.',
                    ]);
                }

                $normalized[$key] = array_filter(
                    ['min' => $min, 'max' => $max],
                    fn (mixed $value): bool => $value !== null,
                );

                continue;
            }

            if (array_keys($selection) !== range(0, count($selection) - 1)) {
                throw ValidationException::withMessages([
                    "attribute_filters.$key" => 'Невалидна структура на филтъра.',
                ]);
            }

            $values = collect($selection)
                ->filter(fn (mixed $value): bool => is_scalar($value))
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values();

            if ($values->isEmpty() || $values->count() > self::MAX_OPTIONS_PER_FILTER) {
                throw ValidationException::withMessages([
                    "attribute_filters.$key" => 'Невалиден брой избрани стойности.',
                ]);
            }

            if ($values->contains(fn (string $value): bool => ! isset($filter['options'][$value]))) {
                throw ValidationException::withMessages([
                    "attribute_filters.$key" => 'Избрана е невалидна стойност.',
                ]);
            }

            $normalized[$key] = ['values' => $values->all()];
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, mixed>>  $selected
     * @param  array<string, array<string, mixed>>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function activeFilters(array $selected, array $filters): array
    {
        return collect($selected)->map(function (array $selection, string $key) use ($filters): array {
            $filter = $filters[$key];

            if ($filter['type'] === 'number_range') {
                return [
                    'key' => $key,
                    'label' => $filter['label'],
                    'type' => 'number_range',
                    'unit' => $filter['unit'],
                    'min' => $selection['min'] ?? null,
                    'max' => $selection['max'] ?? null,
                ];
            }

            return [
                'key' => $key,
                'label' => $filter['label'],
                'type' => $filter['type'],
                'values' => collect($selection['values'])->map(fn (string $value): array => [
                    'key' => $value,
                    'label' => $filter['options'][$value]['label'],
                ])->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, int>  $selectedOptionIds
     * @param  array<int, int>  $validOptionIds
     */
    private function applyMultiselect(Builder $query, array $selectedOptionIds, array $validOptionIds): void
    {
        $query->where(function (Builder $options) use ($selectedOptionIds): void {
            foreach ($selectedOptionIds as $optionId) {
                $options->orWhereJsonContains('product_attribute_values.value_json->attribute_value_ids', $optionId);
            }
        });

        $placeholders = implode(', ', array_fill(0, count($validOptionIds), '?'));

        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(product_attribute_values.value_json, '$.attribute_value_ids')) > 0")
                ->whereRaw(
                    "NOT EXISTS (SELECT 1 FROM JSON_TABLE(JSON_EXTRACT(product_attribute_values.value_json, '$.attribute_value_ids'), '$[*]' COLUMNS(option_id BIGINT PATH '$')) AS public_filter_options WHERE public_filter_options.option_id NOT IN ({$placeholders}))",
                    $validOptionIds,
                );

            return;
        }

        $query
            ->whereRaw("json_array_length(json_extract(product_attribute_values.value_json, '$.attribute_value_ids')) > 0")
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM json_each(json_extract(product_attribute_values.value_json, '$.attribute_value_ids')) AS public_filter_options WHERE CAST(public_filter_options.value AS INTEGER) NOT IN ({$placeholders}))",
                $validOptionIds,
            );
    }

    private function applyNumericRange(Builder $query, ?float $minimum, ?float $maximum): void
    {
        $query->whereNotNull('product_attribute_values.value_number');

        if ($minimum !== null) {
            $query->where('product_attribute_values.value_number', '>=', $minimum);
        }

        if ($maximum !== null) {
            $query->where('product_attribute_values.value_number', '<=', $maximum);
        }
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<int, int>
     */
    private function multiselectIds(?array $value): array
    {
        $ids = $value['attribute_value_ids'] ?? null;

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function validatedNumber(mixed $value, string $key): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || ! is_numeric($value)) {
            throw ValidationException::withMessages([$key => 'Стойността трябва да бъде число.']);
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            throw ValidationException::withMessages([$key => 'Стойността трябва да бъде крайно число.']);
        }

        return $number;
    }

    private function attributeKey(ProductAttribute $attribute): ?string
    {
        $key = trim((string) ($attribute->code ?: $attribute->slug));

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $key) === 1 ? $key : null;
    }

    private function numericValue(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }

    private function scopeKey(Builder $scope): string
    {
        return hash('sha256', $scope->toSql().'|'.json_encode($scope->getBindings(), JSON_THROW_ON_ERROR));
    }
}
