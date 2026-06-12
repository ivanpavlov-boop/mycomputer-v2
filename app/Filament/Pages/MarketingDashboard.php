<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MarketingStats;
use App\Services\Marketing\AnalyticsService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class MarketingDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'Marketing Dashboard';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected string $view = 'filament.pages.marketing-dashboard';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage marketing');
    }

    public function getDashboard(): array
    {
        return app(AnalyticsService::class)->dashboard();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MarketingStats::class,
        ];
    }
}
