<?php

namespace App\Services\Attributes;

use App\Models\SupplierProduct;
use SimpleXMLElement;

class SupplierAttributeExtractionService
{
    public function __construct(private readonly AttributeNormalizationService $normalizer) {}

    public function stage(SupplierProduct $supplierProduct, array $attributes, string $sourceType = 'manual', ?string $sourceCode = null): int
    {
        $supplierProduct->loadMissing('supplier');
        $count = 0;

        foreach ($attributes as $attribute) {
            if (blank($attribute['name'] ?? null) || blank($attribute['value'] ?? null)) {
                continue;
            }

            $this->normalizer->stageAndNormalize([
                'supplier_product_id' => $supplierProduct->id,
                'supplier_id' => $supplierProduct->supplier_id,
                'product_id' => $supplierProduct->product_id,
                'source_type' => $sourceType,
                'source_code' => $sourceCode ?? $supplierProduct->supplier?->company_name,
                'raw_name' => (string) $attribute['name'],
                'raw_value' => (string) $attribute['value'],
                'raw_unit' => $attribute['unit'] ?? null,
            ], $supplierProduct->supplier);

            $count++;
        }

        return $count;
    }

    public function extractFromArray(array $payload): array
    {
        $attributes = $payload['attributes'] ?? $payload['specifications'] ?? $payload['specs'] ?? [];

        if (! is_array($attributes)) {
            return [];
        }

        return collect($attributes)
            ->map(function ($value, $key): ?array {
                if (is_array($value)) {
                    return [
                        'name' => $value['name'] ?? $value['key'] ?? $key,
                        'value' => $value['value'] ?? $value['val'] ?? null,
                        'unit' => $value['unit'] ?? null,
                    ];
                }

                return ['name' => $key, 'value' => $value, 'unit' => null];
            })
            ->filter(fn (?array $attribute): bool => filled($attribute['name'] ?? null) && filled($attribute['value'] ?? null))
            ->values()
            ->all();
    }

    public function extractFromXml(SimpleXMLElement $row): array
    {
        $attributes = [];

        foreach (['attributes.attribute', 'Attributes.Attribute', 'specifications.specification', 'Specifications.Specification', 'specs.spec', 'Specs.Spec'] as $path) {
            foreach ($row->xpath(str_replace('.', '/', $path)) ?: [] as $node) {
                $name = (string) ($node['name'] ?? $node['Name'] ?? $node['key'] ?? $node['Key'] ?? $node->name ?? $node->Name ?? $node->key ?? $node->Key ?? '');
                $value = (string) ($node['value'] ?? $node['Value'] ?? $node->value ?? $node->Value ?? $node);
                $unit = (string) ($node['unit'] ?? $node['Unit'] ?? $node->unit ?? $node->Unit ?? '');

                if (filled($name) && filled($value)) {
                    $attributes[] = ['name' => $name, 'value' => $value, 'unit' => $unit ?: null];
                }
            }
        }

        return $attributes;
    }
}
