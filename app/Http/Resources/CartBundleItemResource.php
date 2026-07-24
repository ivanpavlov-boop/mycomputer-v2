<?php

namespace App\Http\Resources;

use App\Services\Bundles\BundlePricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartBundleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $readiness = $this->resource->relationLoaded('readiness')
            ? $this->resource->getRelation('readiness')
            : null;
        $hasUnsafePricingIssue = collect($readiness['issues'] ?? [])->contains(
            fn (array $issue): bool => in_array(
                $issue['code'] ?? null,
                ['bundle_unavailable', 'bundle_selection_invalid', 'bundle_product_unavailable'],
                true,
            ),
        );
        $pricing = $this->bundle && ! $hasUnsafePricingIssue
            ? app(BundlePricingService::class)->calculate($this->bundle, $this->selected_items)
            : ['original_price' => $this->unit_price, 'savings' => 0];

        return [
            'id' => $this->id,
            'bundle_id' => $this->product_bundle_id,
            'bundle_name' => $this->bundle?->name,
            'selected_items' => $this->selected_items,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_price' => $this->total_price,
            'original_price' => $pricing['original_price'],
            'savings' => $pricing['savings'],
            'readiness' => $readiness,
        ];
    }
}
