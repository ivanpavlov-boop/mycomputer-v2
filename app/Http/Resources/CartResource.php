<?php

namespace App\Http\Resources;

use App\Services\Cart\CartService;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $promotionResult = app(PromotionEngineService::class)->evaluate($this->resource);

        return [
            'id' => $this->id,
            'cart_session_id' => $this->session_id,
            'status' => $this->status,
            'coupon_code' => $this->coupon_code,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'bundle_items' => CartBundleItemResource::collection($this->whenLoaded('bundleItems')),
            'items_count' => $this->items->sum('quantity') + $this->bundleItems->sum('quantity'),
            'subtotal' => app(CartService::class)->subtotal($this->resource),
            'applied_promotions' => $promotionResult['applied_promotions'],
            'promotion_discount_total' => $promotionResult['discount_total'],
            'shipping_discount' => $promotionResult['shipping_discount'],
            'gift_products' => $promotionResult['gift_products'],
            'expires_at' => $this->expires_at,
        ];
    }
}
