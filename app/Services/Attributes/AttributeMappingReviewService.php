<?php

namespace App\Services\Attributes;

use App\Models\AttributeAlias;
use App\Models\AttributeValueAlias;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use App\Models\SupplierProductAttribute;

class AttributeMappingReviewService
{
    public function __construct(private readonly AttributeTextNormalizer $text) {}

    public function approve(SupplierProductAttribute $raw, CanonicalAttribute $attribute, ?CanonicalAttributeValue $value = null, bool $createAliases = true): SupplierProductAttribute
    {
        if ($createAliases) {
            AttributeAlias::query()->firstOrCreate([
                'canonical_attribute_id' => $attribute->id,
                'normalized_alias' => $this->text->normalize($raw->raw_name),
                'supplier_id' => $raw->supplier_id,
            ], [
                'alias' => $raw->raw_name,
                'source_type' => $raw->source_type,
                'confidence' => 90,
                'is_active' => true,
            ]);

            if ($value) {
                AttributeValueAlias::query()->firstOrCreate([
                    'canonical_attribute_value_id' => $value->id,
                    'normalized_alias' => $this->text->normalize($raw->raw_value.' '.($raw->raw_unit ?: '')),
                    'supplier_id' => $raw->supplier_id,
                ], [
                    'alias' => $raw->raw_value,
                    'confidence' => 90,
                    'is_active' => true,
                ]);
            }
        }

        $raw->update([
            'canonical_attribute_id' => $attribute->id,
            'canonical_attribute_value_id' => $value?->id,
            'normalized_name' => $this->text->normalize($raw->raw_name),
            'normalized_value' => $value?->normalized_value,
            'confidence' => 100,
            'status' => 'mapped',
        ]);

        return $raw->fresh(['canonicalAttribute', 'canonicalAttributeValue']);
    }

    public function ignore(SupplierProductAttribute $raw): SupplierProductAttribute
    {
        $raw->update(['status' => 'ignored']);

        return $raw->fresh();
    }
}
