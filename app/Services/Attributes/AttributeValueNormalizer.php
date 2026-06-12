<?php

namespace App\Services\Attributes;

use App\Models\AttributeValueAlias;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use App\Models\Supplier;

class AttributeValueNormalizer
{
    public function __construct(
        private readonly AttributeTextNormalizer $text,
        private readonly UnitConversionService $units,
    ) {}

    public function normalize(CanonicalAttribute $attribute, string $rawValue, ?string $rawUnit = null, ?Supplier $supplier = null): array
    {
        $input = trim($rawValue.' '.($rawUnit ?: ''));
        $normalizedText = $this->text->normalize($input);

        if ($supplier) {
            $alias = $this->aliasQuery($attribute, $normalizedText)->where('supplier_id', $supplier->id)->first();
            if ($alias) {
                return ['value' => $alias->canonicalAttributeValue, 'confidence' => 90, 'normalized' => $alias->canonicalAttributeValue->normalized_value, 'action' => 'mapped'];
            }
        }

        $alias = $this->aliasQuery($attribute, $normalizedText)->whereNull('supplier_id')->first();
        if ($alias) {
            return ['value' => $alias->canonicalAttributeValue, 'confidence' => 100, 'normalized' => $alias->canonicalAttributeValue->normalized_value, 'action' => 'mapped'];
        }

        $converted = $this->units->normalize($input, $attribute->unit);

        $value = CanonicalAttributeValue::query()
            ->where('canonical_attribute_id', $attribute->id)
            ->where('normalized_value', $converted['normalized_value'])
            ->first();

        if (! $value && $converted['confidence'] >= 70) {
            $value = CanonicalAttributeValue::query()->create([
                'canonical_attribute_id' => $attribute->id,
                'normalized_value' => $converted['normalized_value'],
                'display_value' => $converted['display_value'],
                'numeric_value' => $converted['numeric_value'],
                'unit' => $converted['unit'],
                'is_active' => true,
            ]);
        }

        return [
            'value' => $value,
            'confidence' => $value ? $converted['confidence'] : 0,
            'normalized' => $converted['normalized_value'],
            'display_value' => $converted['display_value'],
            'numeric_value' => $converted['numeric_value'],
            'unit' => $converted['unit'],
            'action' => $value ? 'mapped' : 'needs_review',
        ];
    }

    private function aliasQuery(CanonicalAttribute $attribute, string $normalized)
    {
        return AttributeValueAlias::query()
            ->with('canonicalAttributeValue')
            ->where('is_active', true)
            ->where('normalized_alias', $normalized)
            ->whereHas('canonicalAttributeValue', fn ($query) => $query->where('canonical_attribute_id', $attribute->id));
    }
}
