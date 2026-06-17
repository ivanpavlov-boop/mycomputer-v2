<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Services\Reviews\ReviewStatsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reviewSummary = app(ReviewStatsService::class)->summary($this->resource);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'ean' => $this->ean,
            'mpn' => $this->mpn,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'currency' => Product::CATALOG_CURRENCY,
            'price' => $this->price,
            'regular_price' => $this->regular_price ?? $this->price,
            'sale_price' => $this->sale_price ?? $this->promo_price,
            'active_sale_price' => $this->activeSalePrice(),
            'promo_price' => $this->activeSalePrice(),
            'quantity' => $this->quantity,
            'stock_status' => $this->stock_status,
            'availability' => $this->availabilityPayload(),
            'warranty_months' => $this->warranty_months,
            'featured' => $this->featured,
            'new_product' => $this->new_product,
            'bestseller' => $this->bestseller,
            'average_rating' => $reviewSummary['average_rating'],
            'reviews_count' => $reviewSummary['reviews_count'],
            'brand' => BrandResource::make($this->whenLoaded('brand')),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'primary_image' => ProductImageResource::make($this->whenLoaded('images', fn () => $this->images->firstWhere('is_primary', true) ?? $this->images->first())),
        ];
    }

    private function availabilityPayload(): array
    {
        $status = $this->relationLoaded('availabilityStatus') ? $this->availabilityStatus : null;

        return [
            'code' => $status?->code ?? $this->stock_status,
            'name' => $status?->name ?? $this->stock_status,
            'color' => $status?->color ?? 'green',
            'icon' => $status?->icon,
            'badge_style' => $status?->badge_style ?? 'soft',
            'allow_purchase' => (bool) ($status?->allow_purchase ?? $this->stock_status !== 'out_of_stock'),
            'show_stock_quantity' => (bool) ($status?->show_stock_quantity ?? false),
            'message' => $this->availability_message,
            'expected_date' => $this->expected_date?->toDateString(),
            'supplier_lead_time_days' => $this->supplier_lead_time_days,
        ];
    }
}
