<?php

namespace App\Filament\Widgets;

use App\Models\B2BCompany;
use App\Models\QuoteRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class B2BQuoteStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Pending B2B applications', B2BCompany::query()->where('approval_status', 'pending')->count()),
            Stat::make('Open quote requests', QuoteRequest::query()->whereIn('status', ['submitted', 'under_review'])->count()),
            Stat::make('Awaiting response', QuoteRequest::query()->where('status', 'offered')->count()),
            Stat::make('Accepted quotes', QuoteRequest::query()->whereIn('status', ['accepted', 'converted'])->count()),
            Stat::make('Converted quote revenue', 'BGN '.number_format((float) QuoteRequest::query()->where('status', 'converted')->sum('grand_total'), 2)),
        ];
    }
}
