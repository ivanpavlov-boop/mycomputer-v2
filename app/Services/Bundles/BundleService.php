<?php

namespace App\Services\Bundles;

use App\Models\ProductBundle;

class BundleService
{
    public function activeQuery()
    {
        return ProductBundle::query()
            ->available()
            ->with(['items.product.images', 'options.product.images'])
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function findActiveBySlug(string $slug): ProductBundle
    {
        return $this->activeQuery()->where('slug', $slug)->firstOrFail();
    }
}
