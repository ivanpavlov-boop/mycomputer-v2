<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompareItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->whenLoaded('product');
        $publicProduct = $product instanceof Product && $product->active && $product->published_at !== null;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sort_order' => $this->sort_order,
            'product' => $publicProduct ? ProductCardResource::make($product) : null,
            'created_at' => $this->created_at,
        ];
    }
}
