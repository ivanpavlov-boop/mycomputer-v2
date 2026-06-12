<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'points_cost' => $this->points_cost,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'minimum_order_amount' => $this->minimum_order_amount,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
        ];
    }
}
