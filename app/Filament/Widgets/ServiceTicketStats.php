<?php

namespace App\Filament\Widgets;

use App\Models\ServiceTicket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ServiceTicketStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Open Tickets', ServiceTicket::query()->whereNotIn('status', ['completed', 'closed'])->count()),
            Stat::make('Awaiting Customer', ServiceTicket::query()->where('status', 'awaiting_customer')->count()),
            Stat::make('Awaiting Service', ServiceTicket::query()->where('status', 'awaiting_service')->count()),
            Stat::make('In Diagnosis', ServiceTicket::query()->where('status', 'in_diagnosis')->count()),
            Stat::make('Completed Tickets', ServiceTicket::query()->whereIn('status', ['completed', 'closed'])->count()),
        ];
    }
}
