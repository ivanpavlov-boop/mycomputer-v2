<?php

namespace App\Services\Bundles;

use App\Models\Product;
use App\Models\ProductBundle;

class BundleRecommendationService
{
    public function forProduct(Product $product)
    {
        return ProductBundle::query()
            ->available()
            ->where(function ($query) use ($product): void {
                $query
                    ->whereHas('items', fn ($items) => $items->where('product_id', $product->id))
                    ->orWhereHas('options', fn ($options) => $options->where('product_id', $product->id));
            })
            ->with([
                'items.product' => fn ($query) => $query->published()->with('images'),
                'options.product' => fn ($query) => $query->published()->with('images'),
            ])
            ->orderBy('sort_order')
            ->limit(12)
            ->get();
    }
}
