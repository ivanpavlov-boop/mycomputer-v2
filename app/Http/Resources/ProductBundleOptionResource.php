<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductBundleOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component_group' => $this->component_group,
            'price_adjustment' => $this->price_adjustment,
            'is_default' => $this->is_default,
            'product' => ProductCardResource::make($this->whenLoaded('product')),
        ];
    }
}
