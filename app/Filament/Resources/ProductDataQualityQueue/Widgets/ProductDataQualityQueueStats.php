<?php

namespace App\Filament\Resources\ProductDataQualityQueue\Widgets;

use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Services\Products\ProductCategoryBrandQualityResult;
use App\Services\Products\ProductCategoryBrandQualityService;
use App\Services\Products\ProductDataQualityScanner;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductDataQualityQueueStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $scanner = app(ProductDataQualityScanner::class);
        $categoryBrandCounts = app(ProductCategoryBrandQualityService::class)
            ->countsFor($scanner->applyQueueScope(Product::query()));

        return [
            Stat::make('Продукти за преглед', $scanner->applyQueueScope(Product::query())->count()),
            Stat::make('Липсва категория', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY]),
            Stat::make('Липсва марка', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_MISSING_BRAND]),
            Stat::make('Липсват и двете', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_MISSING_BOTH]),
            Stat::make('Попълнени категория и марка', $categoryBrandCounts[ProductCategoryBrandQualityResult::STATE_COMPLETE]),
            Stat::make('Липсващи снимки', Product::query()->doesntHave('images')->count()),
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
