<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => ShippingProviderResource::make($this->whenLoaded('provider')),
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'price' => $this->price,
            'free_shipping_threshold' => $this->free_shipping_threshold,
            'sort_order' => $this->sort_order,
        ];
    }
}
