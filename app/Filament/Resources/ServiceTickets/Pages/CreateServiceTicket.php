<?php

namespace App\Filament\Resources\ServiceTickets\Pages;

use App\Filament\Resources\ServiceTickets\ServiceTicketResource;
use App\Services\Service\ServiceTicketNumberService;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceTicket extends CreateRecord
{
    protected static string $resource = ServiceTicketResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['ticket_number'] ??= app(ServiceTicketNumberService::class)->generate();

        return $data;
    }
}
