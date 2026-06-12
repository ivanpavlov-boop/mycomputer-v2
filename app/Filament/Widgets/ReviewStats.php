<?php

namespace App\Filament\Widgets;

use App\Models\ProductReview;
use App\Models\ProductReviewReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReviewStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Pending reviews', ProductReview::query()->where('status', 'pending')->count()),
            Stat::make('Reported reviews', ProductReviewReport::query()->where('status', 'pending')->count()),
            Stat::make('Average rating', round((float) ProductReview::query()->where('status', 'approved')->avg('rating'), 2)),
            Stat::make('Recent reviews', ProductReview::query()->where('created_at', '>=', now()->subDays(7))->count()),
        ];
    }
}
