<?php

namespace App\Services\Attributes;

use App\Models\AttributeMappingLog;
use App\Models\Supplier;
use App\Models\SupplierProductAttribute;

class AttributeNormalizationService
{
    public const REVIEW_THRESHOLD = 70;

    public function __construct(
        private readonly AttributeNameMapper $names,
        private readonly AttributeValueNormalizer $values,
    ) {}

    public function normalize(SupplierProductAttribute $raw): SupplierProductAttribute
    {
        $supplier = $raw->supplier;
        $nameResult = $this->names->map($raw->raw_name, $supplier, $raw->source_type);
        $attribute = $nameResult['attribute'];

        if (! $attribute) {
            return $this->mark($raw, null, null, $nameResult['normalized'], null, (int) $nameResult['confidence'], 'needs_review', 'Attribute name is not mapped.');
        }

        $valueResult = $this->values->normalize($attribute, $raw->raw_value, $raw->raw_unit, $supplier);
        $confidence = min((int) $nameResult['confidence'], (int) $valueResult['confidence']);
        $status = $confidence >= self::REVIEW_THRESHOLD && $valueResult['value'] ? 'mapped' : 'needs_review';

        return $this->mark(
            $raw,
            $attribute->id,
            $valueResult['value']?->id,
            $nameResult['normalized'],
            $valueResult['normalized'] ?? null,
            $confidence,
            $status,
            $status === 'mapped' ? 'Attribute mapped successfully.' : 'Attribute value requires review.'
        );
    }

    public function stageAndNormalize(array $data, ?Supplier $supplier = null): SupplierProductAttribute
    {
        $raw = SupplierProductAttribute::query()->create([
            'supplier_product_id' => $data['supplier_product_id'] ?? null,
            'supplier_id' => $supplier?->id ?? $data['supplier_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'source_type' => $data['source_type'] ?? 'manual',
            'source_code' => $data['source_code'] ?? $supplier?->company_name,
            'raw_name' => $data['raw_name'],
            'raw_value' => $data['raw_value'],
            'raw_unit' => $data['raw_unit'] ?? null,
            'status' => 'unmapped',
        ]);

        return $this->normalize($raw->load('supplier'));
    }

    private function mark(SupplierProductAttribute $raw, ?int $attributeId, ?int $valueId, ?string $normalizedName, ?string $normalizedValue, int $confidence, string $status, string $message): SupplierProductAttribute
    {
        $raw->update([
            'canonical_attribute_id' => $attributeId,
            'canonical_attribute_value_id' => $valueId,
            'normalized_name' => $normalizedName,
            'normalized_value' => $normalizedValue,
            'confidence' => $confidence,
            'status' => $status,
        ]);

        AttributeMappingLog::query()->create([
            'source_type' => $raw->source_type,
            'source_code' => $raw->source_code,
            'supplier_id' => $raw->supplier_id,
            'raw_name' => $raw->raw_name,
            'raw_value' => $raw->raw_value,
            'mapped_attribute_id' => $attributeId,
            'mapped_value_id' => $valueId,
            'confidence' => $confidence,
            'action' => $status === 'mapped' ? 'mapped' : 'needs_review',
            'message' => $message,
        ]);

        return $raw->fresh(['canonicalAttribute', 'canonicalAttributeValue']);
    }
}
