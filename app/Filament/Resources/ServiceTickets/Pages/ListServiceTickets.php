<?php

namespace App\Filament\Resources\ServiceTickets\Pages;

use App\Filament\Resources\ServiceTickets\ServiceTicketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceTickets extends ListRecords
{
    protected static string $resource = ServiceTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
