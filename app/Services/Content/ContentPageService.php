<?php

namespace App\Services\Content;

use App\Models\ContentPage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class ContentPageService
{
    public function __construct(
        private readonly BlockRenderer $renderer,
        private readonly BlockVisibilityService $visibility,
    ) {}

    public function homepage(?Request $request = null): ?ContentPage
    {
        return ContentPage::query()
            ->published()
            ->where('page_type', 'homepage')
            ->with(['blocks.reusableBlock', 'template'])
            ->latest('published_at')
            ->first();
    }

    public function findPublished(string $slug, ?Request $request = null): ContentPage
    {
        return ContentPage::query()
            ->published()
            ->where('slug', $slug)
            ->with(['blocks.reusableBlock', 'template'])
            ->firstOrFail();
    }

    public function render(ContentPage $page, ?Request $request = null): array
    {
        return $page->blocks
            ->filter(fn ($block): bool => $this->visibility->isVisible($block, $request))
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($block): array => $this->renderer->render($block))
            ->all();
    }

    public function homepageOrFail(?Request $request = null): ContentPage
    {
        return $this->homepage($request) ?? throw new ModelNotFoundException;
    }
}
