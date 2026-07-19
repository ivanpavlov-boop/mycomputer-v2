<?php

namespace App\Services\Content;

use App\Models\SeoPage;

class SeoPageService
{
    public function findPublished(string $slug): SeoPage
    {
        return SeoPage::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'relatedCategory',
                'relatedBrand',
                'relatedProducts' => fn ($query) => $query->published()->with(['brand', 'category', 'images']),
                'relatedCategories',
                'relatedBrands',
            ])
            ->firstOrFail();
    }
}
