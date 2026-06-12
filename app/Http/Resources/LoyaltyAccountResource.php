<?php

namespace App\Http\Resources;

use App\Services\Loyalty\TierCalculationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $next = app(TierCalculationService::class)->nextTier($this->resource);

        return [
            'tier' => $this->tier,
            'points_balance' => $this->points_balance,
            'lifetime_points' => $this->lifetime_points,
            'next_tier' => $next,
            'recent_transactions' => LoyaltyTransactionResource::collection($this->transactions()->latest()->limit(10)->get()),
        ];
    }
}
