<?php

namespace App\Services\Attributes;

use App\Models\AttributeAlias;
use App\Models\CanonicalAttribute;
use App\Models\Supplier;

class AttributeNameMapper
{
    public function __construct(private readonly AttributeTextNormalizer $text) {}

    public function map(string $rawName, ?Supplier $supplier = null, ?string $sourceType = null): array
    {
        $normalized = $this->text->normalize($rawName);
        $code = $this->text->code($rawName);

        if ($supplier) {
            $alias = $this->aliasQuery($normalized)
                ->where('supplier_id', $supplier->id)
                ->first();

            if ($alias) {
                return ['attribute' => $alias->canonicalAttribute, 'confidence' => 90, 'normalized' => $normalized, 'action' => 'mapped'];
            }
        }

        $alias = $this->aliasQuery($normalized)->whereNull('supplier_id')->first();
        if ($alias) {
            return ['attribute' => $alias->canonicalAttribute, 'confidence' => 100, 'normalized' => $normalized, 'action' => 'mapped'];
        }

        $attribute = CanonicalAttribute::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('code', $code)->orWhere('name', $rawName))
            ->first();

        if ($attribute) {
            return ['attribute' => $attribute, 'confidence' => 80, 'normalized' => $normalized, 'action' => 'mapped'];
        }

        return ['attribute' => null, 'confidence' => 0, 'normalized' => $normalized, 'action' => 'needs_review'];
    }

    private function aliasQuery(string $normalized)
    {
        return AttributeAlias::query()
            ->with('canonicalAttribute')
            ->where('is_active', true)
            ->where('normalized_alias', $normalized)
            ->whereHas('canonicalAttribute', fn ($query) => $query->where('is_active', true));
    }
}
