<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PcBuildItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component_type' => $this->component_type,
            'quantity' => $this->quantity,
            'product' => ProductCardResource::make($this->whenLoaded('product')),
        ];
    }
}
