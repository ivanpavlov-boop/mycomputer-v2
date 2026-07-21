<?php

namespace App\Filament\Resources\ProductDataQualityQueue\Widgets;

use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Services\Products\ProductCategoryBrandQualityResult;
use App\Services\Products\ProductCategoryBrandQualityService;
use App\Services\Products\ProductDataQualityScanner;
use App\Services\Products\ProductImageQualityResult;
use App\Services\Products\ProductImageQualityService;
use App\Services\Products\ProductSeoDescriptionQualityResult;
use App\Services\Products\ProductSeoDescriptionQualityService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductDataQualityQueueStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $scanner = app(ProductDataQualityScanner::class);
        $categoryBrandCounts = app(ProductCategoryBrandQualityService::class)
            ->countsFor($scanner->applyQueueScope(Product::query()));
        $imageQuality = app(ProductImageQualityService::class);
        $imageCounts = $imageQuality->countsFor($scanner->applyQueueScope(Product::query()));
        $missingAltCount = $imageQuality->countWithMissingAltFor($scanner->applyQueueScope(Product::query()));
        $seoDescriptionQuality = app(ProductSeoDescriptionQualityService::class);
        $queueScope = fn () => $scanner->applyQueueScope(Product::query());
        $seoDescriptionCounts = $seoDescriptionQuality->countsFor($queueScope());

        return [
            Stat::make('Продукти за преглед', $scanner->applyQueueScope(Product::query())->count()),
            Stat::make('Липсва категория', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY]),
            Stat::make('Липсва марка', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_MISSING_BRAND]),
            Stat::make('Липсват и двете', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_MISSING_BOTH]),
            Stat::make('Попълнени категория и марка', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_COMPLETE]),
            Stat::make('Липсват снимки', $imageCounts[ProductImageQualityResult::STATE_NO_IMAGES]),
            Stat::make('Повече от една основна снимка', $imageCounts[ProductImageQualityResult::STATE_MULTIPLE_PRIMARY]),
            Stat::make('Липсва основна снимка', $imageCounts[ProductImageQualityResult::STATE_MISSING_PRIMARY]),
            Stat::make('Липсва ALT текст', $missingAltCount),
            Stat::make('Снимките са подготвени', $imageCounts[ProductImageQualityResult::STATE_COMPLETE]),
            Stat::make('Липсва SEO', $seoDescriptionQuality->countWithMissingSeoFor($queueScope())),
            Stat::make('Липсват описания', $seoDescriptionQuality->countWithMissingDescriptionsFor($queueScope())),
            Stat::make('Слабо описание', $seoDescriptionQuality->countWithWeakDescriptionFor($queueScope())),
            Stat::make('Липсва EN локализация', $seoDescriptionQuality->countWithMissingEnglishFor($queueScope())),
            Stat::make('Съдържанието е попълнено', $seoDescriptionCounts[ProductSeoDescriptionQualityResult::STATE_COMPLETE]),
            Stat::make('Активни флагове', ProductQualityFlagAssignment::query()->active()->count()),
            Stat::make('Висока важност', ProductQualityFlagAssignment::query()
                ->active()
                ->whereHas('flag', fn ($query) => $query->where('severity', ProductQualityFlag::SEVERITY_HIGH))
                ->count()),
        ];
    }
}
