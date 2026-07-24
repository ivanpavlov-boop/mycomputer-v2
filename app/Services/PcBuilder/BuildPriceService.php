<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuild;

class BuildPriceService
{
    public function recalculate(PcBuild $build): PcBuild
    {
        $total = $build->items()
            ->with('product')
            ->get()
            ->sum(fn ($item): float => $item->product->effectivePrice() * $item->quantity);

        $build->update(['total_price' => $total]);

        return $build->fresh(['items.product.brand', 'items.product.category', 'items.product.images', 'items.product.attributeValues.attribute', 'items.product.attributeValues.value', 'items.product.attributeValues.canonicalAttribute', 'items.product.attributeValues.canonicalAttributeValue']);
    }
}
