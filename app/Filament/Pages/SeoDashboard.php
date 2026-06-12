<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\Redirect;
use App\Models\SeoPage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use UnitEnum;

class SeoDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'SEO Dashboard';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected string $view = 'filament.pages.seo-dashboard';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage marketing');
    }

    public function getStatus(): array
    {
        return [
            'indexed_pages' => SeoPage::query()->published()->count(),
            'sitemap_status' => Route::has('feeds.google-merchant') ? 'available' : 'check routes',
            'robots_status' => 'available',
            'broken_redirects' => Redirect::query()->where('is_active', true)->whereNull('target_url')->count(),
            'missing_meta_titles' => Product::query()->published()->whereNull('meta_title')->count(),
            'missing_meta_descriptions' => Product::query()->published()->whereNull('meta_description')->count(),
            'products_without_images' => Product::query()->published()->doesntHave('images')->count(),
            'products_without_descriptions' => Product::query()->published()->where(function ($query): void {
                $query->whereNull('description')->orWhere('description', '');
            })->count(),
        ];
    }
}
