<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'is_gift' => $this->is_gift,
            'promotion_id' => $this->promotion_id,
            'unit_price' => $this->unit_price,
            'total_price' => $this->total_price,
            'product' => ProductCardResource::make($this->whenLoaded('product')),
            'readiness' => $this->resource->relationLoaded('readiness')
                ? $this->resource->getRelation('readiness')
                : null,
        ];
    }
}
