<?php

namespace App\Filament\Resources\ProductDataQualityQueue\Widgets;

use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Services\Products\ProductDataQualityScanner;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductDataQualityQueueStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $scanner = app(ProductDataQualityScanner::class);

        return [
            Stat::make('Products needing attention', $scanner->applyQueueScope(Product::query())->count()),
            Stat::make('Missing images', Product::query()->doesntHave('images')->count()),
            Stat::make('Missing SEO', $scanner->applyIssueQuery(Product::query(), ProductDataQualityScanner::ISSUE_MISSING_SEO)->count()),
            Stat::make('Missing EN translation', $scanner->applyIssueQuery(Product::query(), ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION)->count()),
            Stat::make('Missing category', Product::query()->whereNull('category_id')->count()),
            Stat::make('Weak descriptions', $scanner->applyIssueQuery(Product::query(), ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION)->count()),
            Stat::make('Open quality flags', ProductQualityFlagAssignment::query()->active()->count()),
            Stat::make('High severity items', ProductQualityFlagAssignment::query()
                ->active()
                ->whereHas('flag', fn ($query) => $query->where('severity', ProductQualityFlag::SEVERITY_HIGH))
                ->count()),
        ];
    }
}
