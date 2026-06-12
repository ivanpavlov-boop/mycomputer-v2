<?php

namespace App\Filament\Widgets;

use App\Models\ProductSyncLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductSyncStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Synced products', ProductSyncLog::query()->where('status', 'synced')->count()),
            Stat::make('Skipped products', ProductSyncLog::query()->where('status', 'skipped')->count()),
            Stat::make('Conflicts', ProductSyncLog::query()->where('status', 'conflict')->count()),
            Stat::make('Duplicates', ProductSyncLog::query()->where('status', 'duplicate')->count()),
        ];
    }
}
