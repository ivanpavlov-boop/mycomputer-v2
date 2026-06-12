<?php

namespace App\Filament\Widgets;

use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Active suppliers', Supplier::query()->where('status', 'active')->count()),
            Stat::make('Active feeds', SupplierFeed::query()->where('status', 'active')->count()),
            Stat::make('Raw supplier products', SupplierProduct::query()->count()),
            Stat::make('Unmapped raw products', SupplierProduct::query()->where('status', 'new')->count()),
        ];
    }
}
