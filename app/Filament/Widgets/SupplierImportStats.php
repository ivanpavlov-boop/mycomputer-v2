<?php

namespace App\Filament\Widgets;

use App\Models\SupplierImportRun;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAttribute;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierImportStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = now()->startOfDay();

        return [
            Stat::make('Imports running', SupplierImportRun::query()->whereIn('status', ['pending', 'running'])->count()),
            Stat::make('Failed imports', SupplierImportRun::query()->where('status', 'failed')->where('created_at', '>=', $today)->count()),
            Stat::make('Completed today', SupplierImportRun::query()->whereIn('status', ['completed', 'completed_with_warnings'])->where('finished_at', '>=', $today)->count()),
            Stat::make('Products updated today', SupplierImportRun::query()->where('finished_at', '>=', $today)->sum('products_updated')),
            Stat::make('Attributes needing review', SupplierProductAttribute::query()->whereIn('status', ['unmapped', 'needs_review'])->count()),
            Stat::make('Availability mappings missing', SupplierProduct::query()->whereNull('availability_status_id')->whereNotNull('external_availability_status')->count()),
            Stat::make('Longest import duration', SupplierImportRun::query()->max('duration_seconds') ?: 0),
        ];
    }
}
