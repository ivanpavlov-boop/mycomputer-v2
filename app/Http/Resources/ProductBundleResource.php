<?php

namespace App\Http\Resources;

use App\Services\Bundles\BundlePricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductBundleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pricing = app(BundlePricingService::class)->calculate($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'pricing_type' => $this->pricing_type,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'image_path' => $this->image_path,
            'original_price' => $pricing['original_price'],
            'price' => $pricing['unit_price'],
            'savings' => $pricing['savings'],
            'items' => ProductBundleItemResource::collection($this->whenLoaded('items')),
            'options' => ProductBundleOptionResource::collection($this->whenLoaded('options')),
            'seo' => [
                'meta_title' => $this->meta_title ?: $this->name,
                'meta_description' => $this->meta_description,
            ],
        ];
    }
}
