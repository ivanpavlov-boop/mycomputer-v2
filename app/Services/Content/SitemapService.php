<?php

namespace App\Services\Content;

use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\SeoPage;
use Illuminate\Support\Collection;

class SitemapService
{
    public function urls(): Collection
    {
        return collect()
            ->merge(Product::query()->published()->get(['slug', 'updated_at'])->map(fn (Product $product): array => [
                'loc' => url("/p/{$product->slug}"),
                'lastmod' => $product->updated_at?->toDateString(),
            ]))
            ->merge(ProductBundle::query()->available()->get(['slug', 'updated_at'])->map(fn (ProductBundle $bundle): array => [
                'loc' => url("/bundles/{$bundle->slug}"),
                'lastmod' => $bundle->updated_at?->toDateString(),
            ]))
            ->merge(Category::query()->where('is_active', true)->get(['slug', 'updated_at'])->map(fn (Category $category): array => [
                'loc' => url("/c/{$category->slug}"),
                'lastmod' => $category->updated_at?->toDateString(),
            ]))
            ->merge(Brand::query()->where('is_active', true)->get(['slug', 'updated_at'])->map(fn (Brand $brand): array => [
                'loc' => url("/brand/{$brand->slug}"),
                'lastmod' => $brand->updated_at?->toDateString(),
            ]))
            ->merge(BlogPost::query()->published()->get(['slug', 'updated_at'])->map(fn (BlogPost $post): array => [
                'loc' => url("/blog/{$post->slug}"),
                'lastmod' => $post->updated_at?->toDateString(),
            ]))
            ->merge(SeoPage::query()->published()->get(['slug', 'updated_at'])->map(fn (SeoPage $page): array => [
                'loc' => url("/guide/{$page->slug}"),
                'lastmod' => $page->updated_at?->toDateString(),
            ]))
            ->merge(ContentPage::query()->published()->where('page_type', '!=', 'homepage')->get(['slug', 'updated_at'])->map(fn (ContentPage $page): array => [
                'loc' => url("/pages/{$page->slug}"),
                'lastmod' => $page->updated_at?->toDateString(),
            ]));
    }

    public function xml(): string
    {
        $items = $this->urls()
            ->map(fn (array $url): string => sprintf(
                '<url><loc>%s</loc>%s</url>',
                e($url['loc']),
                $url['lastmod'] ? '<lastmod>'.e($url['lastmod']).'</lastmod>' : '',
            ))
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$items.'</urlset>';
    }
}
