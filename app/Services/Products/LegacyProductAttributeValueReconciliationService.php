<?php

namespace App\Services\Products;

use App\Models\AttributeValue;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LegacyProductAttributeValueReconciliationService
{
    public const ACTION_WOULD_CREATE = 'would_create';

    public const ACTION_TARGET_ALREADY_FILLED = 'target_already_filled';

    public const ACTION_NEEDS_MANUAL_REVIEW = 'needs_manual_review';

    public const ACTION_MISSING_TARGET_ATTRIBUTE = 'missing_target_attribute';

    public const ACTION_MISSING_TARGET_OPTION = 'missing_target_option';

    public const ACTION_SKIPPED_AMBIGUOUS = 'skipped_ambiguous';

    public const VISIBILITY_CATEGORY = 'category';

    public const VISIBILITY_EXTRA = 'extra';

    public const VISIBILITY_RECONCILED_LEGACY = 'reconciled_legacy';

    public const VISIBILITY_PARTIALLY_RECONCILED_LEGACY = 'partially_reconciled_legacy';

    public const VISIBILITY_NEEDS_REVIEW = 'needs_review';

    /**
     * @param  array{attribute?: string|null, only_missing_quality?: bool}  $filters
     * @return array{legacy_values_found: int, proposals: Collection<int, array<string, mixed>>}
     */
    public function preview(Product $product, array $filters = []): array
    {
        $product->loadMissing([
            'category.parent',
            'attributeValues.attribute',
            'attributeValues.value',
        ]);

        if (($filters['only_missing_quality'] ?? false) === true
            && app(ProductSpecificationQualityService::class)->evaluate($product)->missingCount === 0) {
            return [
                'legacy_values_found' => 0,
                'proposals' => collect(),
            ];
        }

        $categoryAttributeIds = $this->categoryAttributeIdsForProduct($product);
        $attributeFilter = $this->normalizedFilter($filters['attribute'] ?? null);

        $legacyValues = $product->attributeValues
            ->filter(fn (ProductAttributeValue $value): bool => ! in_array((int) $value->product_attribute_id, $categoryAttributeIds, true))
            ->filter(function (ProductAttributeValue $value) use ($attributeFilter): bool {
                if ($attributeFilter === null) {
                    return true;
                }

                $attribute = $value->attribute;

                return $attribute instanceof ProductAttribute
                    && $this->attributeMatchesFilter($attribute, $attributeFilter);
            })
            ->values();

        return [
            'legacy_values_found' => $legacyValues->count(),
            'proposals' => $legacyValues
                ->flatMap(fn (ProductAttributeValue $value): array => $this->proposalsForLegacyValue($product, $value, $categoryAttributeIds))
                ->values(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $proposals
     * @return array{created: int, skipped: int}
     */
    public function apply(Collection $proposals): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($proposals as $proposal) {
            if (($proposal['action'] ?? null) !== self::ACTION_WOULD_CREATE || empty($proposal['payload'])) {
                $skipped++;

                continue;
            }

            /** @var ProductAttribute|null $targetAttribute */
            $targetAttribute = $proposal['target_attribute'] ?? null;

            if (! $targetAttribute || $this->targetAlreadyFilled((int) $proposal['product_id'], $targetAttribute)) {
                $skipped++;

                continue;
            }

            ProductAttributeValue::query()->create($proposal['payload']);
            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{
     *     classification: string,
     *     proposals: Collection<int, array<string, mixed>>,
     *     expected_target_count: int,
     *     filled_target_count: int,
     *     target_codes: array<int, string>,
     *     filled_target_codes: array<int, string>
     * }
     */
    public function visibilityClassification(Product $product, ProductAttributeValue $legacyValue): array
    {
        $product->loadMissing([
            'category.parent',
            'attributeValues.attribute',
            'attributeValues.value',
        ]);
        $legacyValue->loadMissing(['attribute', 'value']);

        $categoryAttributeIds = $this->categoryAttributeIdsForProduct($product);

        if (in_array((int) $legacyValue->product_attribute_id, $categoryAttributeIds, true)) {
            return $this->visibilityResult(self::VISIBILITY_CATEGORY);
        }

        $proposals = collect($this->proposalsForLegacyValue($product, $legacyValue, $categoryAttributeIds));

        if ($proposals->isEmpty()) {
            return $this->visibilityResult(self::VISIBILITY_EXTRA, $proposals);
        }

        if ($proposals->contains(fn (array $proposal): bool => ($proposal['action'] ?? null) === self::ACTION_SKIPPED_AMBIGUOUS)) {
            return $this->visibilityResult(self::VISIBILITY_NEEDS_REVIEW, $proposals);
        }

        $expectedTargets = $proposals
            ->mapWithKeys(function (array $proposal): array {
                $code = $proposal['target_code'] ?? $proposal['target_attribute']?->code ?? null;

                return filled($code) ? [(string) $code => $proposal] : [];
            });

        if ($expectedTargets->isEmpty()) {
            return $this->visibilityResult(self::VISIBILITY_NEEDS_REVIEW, $proposals);
        }

        $filledTargets = $expectedTargets
            ->filter(function (array $proposal) use ($product): bool {
                $targetAttribute = $proposal['target_attribute'] ?? null;

                if (! $targetAttribute instanceof ProductAttribute) {
                    return false;
                }

                $targetValue = ProductAttributeValue::query()
                    ->with('value')
                    ->where('product_id', $product->id)
                    ->where('product_attribute_id', $targetAttribute->id)
                    ->first();

                return app(ProductSpecificationQualityService::class)->hasFilledValue($targetAttribute, $targetValue);
            });

        if ($filledTargets->count() === $expectedTargets->count()) {
            return $this->visibilityResult(
                self::VISIBILITY_RECONCILED_LEGACY,
                $proposals,
                $expectedTargets->keys()->values()->all(),
                $filledTargets->keys()->values()->all(),
            );
        }

        if ($filledTargets->isNotEmpty()) {
            return $this->visibilityResult(
                self::VISIBILITY_PARTIALLY_RECONCILED_LEGACY,
                $proposals,
                $expectedTargets->keys()->values()->all(),
                $filledTargets->keys()->values()->all(),
            );
        }

        return $this->visibilityResult(
            self::VISIBILITY_NEEDS_REVIEW,
            $proposals,
            $expectedTargets->keys()->values()->all(),
            [],
        );
    }

    /**
     * @param  array<int, int>  $categoryAttributeIds
     * @return array<int, array<string, mixed>>
     */
    private function proposalsForLegacyValue(Product $product, ProductAttributeValue $legacyValue, array $categoryAttributeIds): array
    {
        $sourceAttribute = $legacyValue->attribute;
        $sourceValue = $this->displayValue($legacyValue);

        if (! $sourceAttribute || blank($sourceValue)) {
            return [$this->proposal($product, $legacyValue, null, null, self::ACTION_NEEDS_MANUAL_REVIEW, 'empty_source_value')];
        }

        $targets = $this->targetCandidates($sourceAttribute, $sourceValue);

        if ($targets === []) {
            return [$this->proposal($product, $legacyValue, null, null, self::ACTION_NEEDS_MANUAL_REVIEW, 'unknown_legacy_attribute')];
        }

        return collect($targets)
            ->map(fn (array $target): array => $this->proposalForTarget($product, $legacyValue, $target, $categoryAttributeIds))
            ->all();
    }

    /**
     * @param  array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}  $target
     * @param  array<int, int>  $categoryAttributeIds
     * @return array<string, mixed>
     */
    private function proposalForTarget(Product $product, ProductAttributeValue $legacyValue, array $target, array $categoryAttributeIds): array
    {
        $targetAttribute = $this->resolveTargetAttribute($target['code']);

        if (! $targetAttribute || ! in_array((int) $targetAttribute->id, $categoryAttributeIds, true)) {
            return $this->proposal(
                $product,
                $legacyValue,
                $targetAttribute,
                $target['value'] ?? null,
                self::ACTION_MISSING_TARGET_ATTRIBUTE,
                'target_attribute_missing_or_not_assigned',
                $target,
            );
        }

        if (($target['ambiguous'] ?? false) === true) {
            return $this->proposal(
                $product,
                $legacyValue,
                $targetAttribute,
                $target['value'] ?? null,
                self::ACTION_SKIPPED_AMBIGUOUS,
                $target['reason'] ?? 'ambiguous_value',
                $target,
            );
        }

        if ($this->targetAlreadyFilled((int) $product->id, $targetAttribute)) {
            return $this->proposal(
                $product,
                $legacyValue,
                $targetAttribute,
                $target['value'] ?? null,
                self::ACTION_TARGET_ALREADY_FILLED,
                'target_already_filled',
                $target,
            );
        }

        $payload = $this->payloadForTarget($product, $targetAttribute, $target);

        if ($payload === null) {
            return $this->proposal(
                $product,
                $legacyValue,
                $targetAttribute,
                $target['value'] ?? null,
                self::ACTION_MISSING_TARGET_OPTION,
                'missing_target_option',
                $target,
            );
        }

        return $this->proposal(
            $product,
            $legacyValue,
            $targetAttribute,
            $target['value'] ?? null,
            self::ACTION_WOULD_CREATE,
            $target['reason'],
            $target,
            $payload,
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>|null  $proposals
     * @param  array<int, string>  $targetCodes
     * @param  array<int, string>  $filledTargetCodes
     * @return array{
     *     classification: string,
     *     proposals: Collection<int, array<string, mixed>>,
     *     expected_target_count: int,
     *     filled_target_count: int,
     *     target_codes: array<int, string>,
     *     filled_target_codes: array<int, string>
     * }
     */
    private function visibilityResult(
        string $classification,
        ?Collection $proposals = null,
        array $targetCodes = [],
        array $filledTargetCodes = [],
    ): array {
        return [
            'classification' => $classification,
            'proposals' => $proposals ?? collect(),
            'expected_target_count' => count($targetCodes),
            'filled_target_count' => count($filledTargetCodes),
            'target_codes' => array_values($targetCodes),
            'filled_target_codes' => array_values($filledTargetCodes),
        ];
    }

    /**
     * @param  array{code: string, value: mixed, confidence: string, reason: string, unit?: string|null}  $target
     * @return array<string, mixed>|null
     */
    private function payloadForTarget(Product $product, ProductAttribute $targetAttribute, array $target): ?array
    {
        $value = $target['value'] ?? null;
        $base = [
            'product_id' => $product->id,
            'product_attribute_id' => $targetAttribute->id,
            'attribute_value_id' => null,
            'custom_value' => is_scalar($value) ? (string) $value : null,
            'value_text' => is_scalar($value) ? (string) $value : null,
            'value_number' => null,
            'value_boolean' => null,
            'value_json' => null,
            'unit' => $target['unit'] ?? $targetAttribute->unit,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_verified' => false,
            'sort_order' => 0,
            'is_filterable' => (bool) $targetAttribute->is_filterable,
        ];

        return match ($targetAttribute->type) {
            ProductAttribute::TYPE_TEXT => $base,
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => $this->numericPayload($base, $value),
            ProductAttribute::TYPE_BOOLEAN => $this->booleanPayload($base, $value),
            ProductAttribute::TYPE_SELECT => $this->selectPayload($base, $targetAttribute, $value),
            ProductAttribute::TYPE_MULTISELECT => $this->multiselectPayload($base, $targetAttribute, $value),
            ProductAttribute::TYPE_JSON => array_merge($base, ['value_json' => ['value' => $value]]),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function numericPayload(array $base, mixed $value): ?array
    {
        if (! is_numeric($value)) {
            return null;
        }

        return array_merge($base, [
            'custom_value' => (string) $value,
            'value_text' => null,
            'value_number' => (float) $value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function booleanPayload(array $base, mixed $value): ?array
    {
        $normalized = strtolower(trim((string) $value));

        if (! in_array($normalized, ['true', 'false', '1', '0', 'yes', 'no'], true)) {
            return null;
        }

        $boolean = in_array($normalized, ['true', '1', 'yes'], true);

        return array_merge($base, [
            'custom_value' => $boolean ? 'true' : 'false',
            'value_text' => null,
            'value_boolean' => $boolean,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function selectPayload(array $base, ProductAttribute $targetAttribute, mixed $value): ?array
    {
        $option = $this->findOption($targetAttribute, (string) $value);

        if (! $option) {
            return null;
        }

        return array_merge($base, [
            'attribute_value_id' => $option->id,
            'custom_value' => $option->value,
            'value_text' => $option->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function multiselectPayload(array $base, ProductAttribute $targetAttribute, mixed $value): ?array
    {
        $option = $this->findOption($targetAttribute, (string) $value);

        if (! $option) {
            return null;
        }

        return array_merge($base, [
            'custom_value' => $option->value,
            'value_text' => $option->value,
            'value_json' => ['attribute_value_ids' => [$option->id]],
        ]);
    }

    private function findOption(ProductAttribute $targetAttribute, string $value): ?AttributeValue
    {
        $slug = Str::slug($value);
        $normalized = $this->normalizeComparable($value);

        return AttributeValue::query()
            ->where('product_attribute_id', $targetAttribute->id)
            ->where('is_active', true)
            ->get()
            ->first(function (AttributeValue $option) use ($slug, $normalized): bool {
                $translations = $option->value_translations ?? [];

                return $option->slug === $slug
                    || $this->normalizeComparable($option->value) === $normalized
                    || collect($translations)
                        ->contains(fn (mixed $translation): bool => $this->normalizeComparable((string) $translation) === $normalized);
            });
    }

    private function targetAlreadyFilled(int $productId, ProductAttribute $targetAttribute): bool
    {
        return ProductAttributeValue::query()
            ->where('product_id', $productId)
            ->where('product_attribute_id', $targetAttribute->id)
            ->exists();
    }

    /**
     * @param  array<int, int>  $categoryAttributeIds
     * @return array<string, mixed>
     */
    private function proposal(
        Product $product,
        ProductAttributeValue $legacyValue,
        ?ProductAttribute $targetAttribute,
        mixed $parsedValue,
        string $action,
        string $reason,
        array $target = [],
        ?array $payload = null,
    ): array {
        $sourceAttribute = $legacyValue->attribute;

        return [
            'product_id' => (int) $product->id,
            'product_sku' => $product->sku,
            'product_name' => $product->name,
            'source_value_id' => (int) $legacyValue->id,
            'source_attribute_id' => (int) $legacyValue->product_attribute_id,
            'source_attribute_code' => $sourceAttribute?->code,
            'source_attribute_name' => $this->attributeLabel($sourceAttribute),
            'source_value' => $this->displayValue($legacyValue),
            'target_attribute' => $targetAttribute,
            'target_attribute_id' => $targetAttribute?->id,
            'target_code' => $target['code'] ?? $targetAttribute?->code,
            'target_name' => $this->attributeLabel($targetAttribute),
            'parsed_value' => is_scalar($parsedValue) ? (string) $parsedValue : null,
            'confidence' => $target['confidence'] ?? 'low',
            'reason' => $reason,
            'action' => $action,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function targetCandidates(ProductAttribute $sourceAttribute, string $sourceValue): array
    {
        $identity = $this->normalizeComparable(implode(' ', array_filter([
            $sourceAttribute->code,
            $sourceAttribute->slug,
            $sourceAttribute->name,
            $sourceAttribute->name_bg,
            $sourceAttribute->name_en,
        ])));

        if (Str::contains($identity, ['storage', 'pamet'])) {
            return $this->storageTargets($sourceValue);
        }

        if (Str::contains($identity, ['display', 'screen'])) {
            return $this->screenSizeTargets($sourceValue);
        }

        if (Str::contains($identity, ['ram', 'memory'])) {
            return $this->capacityTarget('ram', $sourceValue, 'ram_capacity_parsed');
        }

        if (Str::contains($identity, ['processor', 'cpu'])) {
            return $this->textTarget('processor', $sourceValue, 'processor_text_copied');
        }

        if (Str::contains($identity, ['socket'])) {
            return $this->textTarget('cpu_socket', $sourceValue, 'cpu_socket_text_copied');
        }

        if (Str::contains($identity, ['core', 'cores'])) {
            return $this->numericFromTextTarget('cpu_cores', $sourceValue, null, 'cpu_cores_parsed');
        }

        if (Str::contains($identity, ['thread', 'threads'])) {
            return $this->numericFromTextTarget('cpu_threads', $sourceValue, null, 'cpu_threads_parsed');
        }

        if (Str::contains($identity, ['boost_clock', 'boost clock', 'boost_frequency', 'boost frequency', 'max_clock', 'max clock'])) {
            return $this->numericFromTextTarget('cpu_boost_clock', $sourceValue, 'GHz', 'cpu_boost_clock_parsed');
        }

        if (Str::contains($identity, ['base_clock', 'base clock', 'base_frequency', 'base frequency'])) {
            return $this->numericFromTextTarget('cpu_base_clock', $sourceValue, 'GHz', 'cpu_base_clock_parsed');
        }

        if (Str::contains($identity, ['tdp'])) {
            return $this->numericFromTextTarget('cpu_tdp', $sourceValue, 'W', 'cpu_tdp_parsed');
        }

        if (Str::contains($identity, ['cache'])) {
            return $this->textTarget('cpu_cache', $sourceValue, 'cpu_cache_text_copied');
        }

        if (Str::contains($identity, ['architecture'])) {
            return $this->textTarget('cpu_architecture', $sourceValue, 'cpu_architecture_text_copied');
        }

        if (Str::contains($identity, ['integrated_graphics', 'integrated graphics', 'igpu'])) {
            return $this->textTarget('cpu_integrated_graphics', $sourceValue, 'cpu_integrated_graphics_text_copied');
        }

        if (Str::contains($identity, ['gpu', 'graphics', 'video'])) {
            return $this->textTarget('gpu', $sourceValue, 'gpu_text_copied');
        }

        if (Str::contains($identity, ['resolution'])) {
            return $this->textTarget('resolution', $sourceValue, 'resolution_text_copied');
        }

        if (Str::contains($identity, ['refresh_rate', 'refresh rate', 'refresh-rate', ' hz', 'hz'])) {
            return $this->refreshRateTargets($sourceValue);
        }

        if (Str::contains($identity, ['operating_system', 'operating system', ' os ', 'os'])) {
            return $this->textTarget('operating_system', $sourceValue, 'operating_system_text_copied');
        }

        if (Str::contains($identity, ['color', 'colour'])) {
            return $this->textTarget('color', $sourceValue, 'color_text_copied');
        }

        if (Str::contains($identity, ['warranty'])) {
            return $this->warrantyTargets($sourceValue);
        }

        if (Str::contains($identity, ['weight'])) {
            return $this->numericFromTextTarget('weight', $sourceValue, 'kg', 'weight_parsed');
        }

        if (Str::contains($identity, ['power'])) {
            return $this->numericFromTextTarget('power_watts', $sourceValue, 'W', 'power_watts_parsed');
        }

        if (Str::contains($identity, ['interface'])) {
            return $this->textTarget('interface', $sourceValue, 'interface_text_copied');
        }

        if (Str::contains($identity, ['connectors', 'ports'])) {
            return $this->textTarget('connectors', $sourceValue, 'connectors_text_copied');
        }

        return [];
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function storageTargets(string $sourceValue): array
    {
        $targets = [];
        $capacities = $this->capacitiesFromText($sourceValue);

        if (count($capacities) === 1) {
            $targets[] = [
                'code' => 'storage_capacity',
                'value' => $capacities[0],
                'confidence' => 'high',
                'reason' => 'storage_capacity_parsed',
            ];
        } elseif (count($capacities) > 1) {
            $targets[] = [
                'code' => 'storage_capacity',
                'value' => implode(', ', $capacities),
                'confidence' => 'low',
                'reason' => 'multiple_storage_capacities_found',
                'ambiguous' => true,
            ];
        }

        $types = $this->storageTypesFromText($sourceValue);

        if (count($types) === 1) {
            $targets[] = [
                'code' => 'storage_type',
                'value' => $types[0],
                'confidence' => 'high',
                'reason' => 'storage_type_parsed',
            ];
        } elseif (count($types) > 1) {
            $targets[] = [
                'code' => 'storage_type',
                'value' => implode(', ', $types),
                'confidence' => 'low',
                'reason' => 'multiple_storage_types_found',
                'ambiguous' => true,
            ];
        }

        return $targets === []
            ? [[
                'code' => 'storage_capacity',
                'value' => $sourceValue,
                'confidence' => 'low',
                'reason' => 'storage_value_unparseable',
                'ambiguous' => true,
            ]]
            : $targets;
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function screenSizeTargets(string $sourceValue): array
    {
        preg_match_all('/(\d+(?:[\.,]\d+)?)\s*(?:"|inch|inches|in\b)/i', $sourceValue, $matches);
        $sizes = collect($matches[1] ?? [])
            ->map(fn (string $size): string => $this->formatDecimalString($size).'"')
            ->unique()
            ->values()
            ->all();

        if (count($sizes) === 1) {
            return [[
                'code' => 'screen_size',
                'value' => $sizes[0],
                'confidence' => 'high',
                'reason' => 'screen_size_parsed',
            ]];
        }

        return [[
            'code' => 'screen_size',
            'value' => $sourceValue,
            'confidence' => 'low',
            'reason' => count($sizes) > 1 ? 'multiple_screen_sizes_found' : 'screen_size_unparseable',
            'ambiguous' => true,
        ]];
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function capacityTarget(string $code, string $sourceValue, string $reason): array
    {
        $capacities = $this->capacitiesFromText($sourceValue, ['GB']);

        if (count($capacities) === 1) {
            return [[
                'code' => $code,
                'value' => $capacities[0],
                'confidence' => 'high',
                'reason' => $reason,
            ]];
        }

        return [[
            'code' => $code,
            'value' => $sourceValue,
            'confidence' => 'low',
            'reason' => count($capacities) > 1 ? 'multiple_capacities_found' : 'capacity_unparseable',
            'ambiguous' => true,
        ]];
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function refreshRateTargets(string $sourceValue): array
    {
        preg_match_all('/(\d+(?:[\.,]\d+)?)\s*hz\b/i', $sourceValue, $matches);
        $rates = collect($matches[1] ?? [])
            ->map(fn (string $rate): string => $this->formatDecimalString($rate).' Hz')
            ->unique()
            ->values()
            ->all();

        if (count($rates) === 1) {
            return [[
                'code' => 'refresh_rate',
                'value' => $rates[0],
                'confidence' => 'high',
                'reason' => 'refresh_rate_parsed',
            ]];
        }

        return [[
            'code' => 'refresh_rate',
            'value' => $sourceValue,
            'confidence' => 'low',
            'reason' => count($rates) > 1 ? 'multiple_refresh_rates_found' : 'refresh_rate_unparseable',
            'ambiguous' => true,
        ]];
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function warrantyTargets(string $sourceValue): array
    {
        if (! preg_match('/(\d+(?:[\.,]\d+)?)\s*(month|months|mo|year|years|yr|yrs)?/i', $sourceValue, $match)) {
            return [[
                'code' => 'warranty_months',
                'value' => $sourceValue,
                'confidence' => 'low',
                'reason' => 'warranty_unparseable',
                'ambiguous' => true,
            ]];
        }

        $number = (float) str_replace(',', '.', $match[1]);
        $unit = strtolower($match[2] ?? 'months');
        $months = str_starts_with($unit, 'year') || in_array($unit, ['yr', 'yrs'], true)
            ? (int) round($number * 12)
            : (int) round($number);

        return [[
            'code' => 'warranty_months',
            'value' => $months,
            'confidence' => 'medium',
            'reason' => 'warranty_months_parsed',
            'unit' => 'months',
        ]];
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function numericFromTextTarget(string $code, string $sourceValue, ?string $unit, string $reason): array
    {
        preg_match_all('/(\d+(?:[\.,]\d+)?)/', $sourceValue, $matches);
        $numbers = collect($matches[1] ?? [])
            ->map(fn (string $number): string => (string) (float) str_replace(',', '.', $number))
            ->unique()
            ->values()
            ->all();

        if (count($numbers) === 1) {
            return [[
                'code' => $code,
                'value' => $numbers[0],
                'confidence' => 'medium',
                'reason' => $reason,
                'unit' => $unit,
            ]];
        }

        return [[
            'code' => $code,
            'value' => $sourceValue,
            'confidence' => 'low',
            'reason' => count($numbers) > 1 ? 'multiple_numeric_values_found' : 'numeric_value_unparseable',
            'ambiguous' => true,
            'unit' => $unit,
        ]];
    }

    /**
     * @return array<int, array{code: string, value: mixed, confidence: string, reason: string, ambiguous?: bool, unit?: string|null}>
     */
    private function textTarget(string $code, string $sourceValue, string $reason): array
    {
        return [[
            'code' => $code,
            'value' => trim($sourceValue),
            'confidence' => 'medium',
            'reason' => $reason,
        ]];
    }

    /**
     * @param  array<int, string>  $units
     * @return array<int, string>
     */
    private function capacitiesFromText(string $sourceValue, array $units = ['GB', 'TB']): array
    {
        preg_match_all('/(?<!\d)(\d+(?:[\.,]\d+)?)\s*('.implode('|', $units).')\b/i', $sourceValue, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $match): string => $this->formatDecimalString($match[1]).' '.strtoupper($match[2]))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function storageTypesFromText(string $sourceValue): array
    {
        $types = [];

        if (preg_match('/\bnvme\b/i', $sourceValue)) {
            $types[] = 'NVMe';
        } else {
            if (preg_match('/\bssd\b/i', $sourceValue)) {
                $types[] = 'SSD';
            }

            if (preg_match('/\bhdd\b/i', $sourceValue)) {
                $types[] = 'HDD';
            }
        }

        return array_values(array_unique($types));
    }

    private function resolveTargetAttribute(string $code): ?ProductAttribute
    {
        $attribute = ProductAttribute::query()
            ->where('code', $code)
            ->first();

        if ($attribute) {
            return $attribute;
        }

        return ProductAttribute::query()
            ->where('slug', Str::slug($code))
            ->first();
    }

    /**
     * @return array<int, int>
     */
    private function categoryAttributeIdsForProduct(Product $product): array
    {
        $categoryIds = $this->categoryIdsForProduct($product);

        if ($categoryIds === []) {
            return [];
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
            ->pluck('product_attribute_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
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

    private function displayValue(ProductAttributeValue $value): string
    {
        if (filled($value->value_text)) {
            return trim((string) $value->value_text);
        }

        if (filled($value->custom_value)) {
            return trim((string) $value->custom_value);
        }

        if ($value->value) {
            return trim((string) $value->value->value);
        }

        if ($value->value_number !== null) {
            return trim((string) $value->value_number.' '.($value->unit ?? ''));
        }

        if ($value->value_boolean !== null) {
            return $value->value_boolean ? 'true' : 'false';
        }

        if (is_array($value->value_json) && $value->value_json !== []) {
            return json_encode($value->value_json) ?: '';
        }

        return '';
    }

    private function attributeMatchesFilter(ProductAttribute $attribute, string $filter): bool
    {
        return collect([
            $attribute->code,
            $attribute->slug,
            $attribute->name,
            $attribute->name_bg,
            $attribute->name_en,
        ])->contains(fn (?string $value): bool => $value !== null && $this->normalizedFilter($value) === $filter);
    }

    private function attributeLabel(?ProductAttribute $attribute): ?string
    {
        if (! $attribute) {
            return null;
        }

        return $attribute->name_bg ?: $attribute->name ?: $attribute->code;
    }

    private function normalizedFilter(?string $value): ?string
    {
        return filled($value) ? Str::slug((string) $value, '_') : null;
    }

    private function normalizeComparable(string $value): string
    {
        return str_replace('-', '_', Str::slug(Str::lower($value), '_'));
    }

    private function formatDecimalString(string $value): string
    {
        $number = rtrim(rtrim(str_replace(',', '.', $value), '0'), '.');

        return $number === '' ? '0' : $number;
    }
}
