<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'group' => [
                'id' => $this->attribute?->group?->id,
                'name' => $this->canonicalAttribute?->group_name ?? $this->attribute?->group?->name,
                'slug' => $this->attribute?->group?->slug,
            ],
            'attribute' => [
                'id' => $this->attribute?->id,
                'code' => $this->canonicalAttribute?->code ?? $this->attribute?->slug,
                'name' => $this->canonicalAttribute?->name ?? $this->attribute?->name,
                'slug' => $this->canonicalAttribute?->code ?? $this->attribute?->slug,
                'unit' => $this->canonicalAttribute?->unit ?? $this->attribute?->unit,
                'is_filterable' => (bool) ($this->canonicalAttribute?->is_filterable ?? $this->is_filterable),
                'is_comparable' => (bool) ($this->canonicalAttribute?->is_comparable ?? true),
            ],
            'value' => [
                'id' => $this->value?->id,
                'value' => $this->canonicalAttributeValue?->display_value ?? $this->value?->value ?? $this->custom_value,
                'display_value' => $this->canonicalAttributeValue?->display_value ?? $this->value?->value ?? $this->custom_value,
                'slug' => $this->canonicalAttributeValue?->normalized_value ?? $this->value?->slug,
                'numeric_value' => $this->canonicalAttributeValue?->numeric_value,
                'unit' => $this->canonicalAttributeValue?->unit,
            ],
        ];
    }
}
