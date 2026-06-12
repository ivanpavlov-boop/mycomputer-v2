<?php

namespace App\Filament\Widgets;

use App\Models\ErpSyncJob;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ErpSyncStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Pending ERP sync jobs', ErpSyncJob::query()->where('status', 'pending')->count()),
            Stat::make('Failed ERP sync jobs', ErpSyncJob::query()->where('status', 'failed')->count()),
            Stat::make('Successful ERP syncs today', ErpSyncJob::query()->where('status', 'success')->whereDate('synced_at', today())->count()),
            Stat::make('Last ERP sync', ErpSyncJob::query()->whereNotNull('synced_at')->latest('synced_at')->value('synced_at')?->diffForHumans() ?? 'Never'),
        ];
    }
}
