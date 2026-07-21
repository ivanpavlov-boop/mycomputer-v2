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
            Stat::make('Липсва SEO', $scanner->applyIssueQuery(Product::query(), ProductDataQualityScanner::ISSUE_MISSING_SEO)->count()),
            Stat::make('Липсва EN превод', $scanner->applyIssueQuery(Product::query(), ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION)->count()),
            Stat::make('Слаби описания', $scanner->applyIssueQuery(Product::query(), ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION)->count()),
            Stat::make('Активни флагове', ProductQualityFlagAssignment::query()->active()->count()),
            Stat::make('Висока важност', ProductQualityFlagAssignment::query()
                ->active()
                ->whereHas('flag', fn ($query) => $query->where('severity', ProductQualityFlag::SEVERITY_HIGH))
                ->count()),
        ];
    }
}
