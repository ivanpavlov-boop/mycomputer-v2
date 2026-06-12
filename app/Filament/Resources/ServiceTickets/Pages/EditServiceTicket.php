<?php

namespace App\Filament\Resources\ServiceTickets\Pages;

use App\Filament\Resources\ServiceTickets\ServiceTicketResource;
use App\Models\ServiceTicket;
use App\Services\Service\ServiceTicketService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;

class EditServiceTicket extends EditRecord
{
    protected static string $resource = ServiceTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('internal_note')
                ->label('Add internal note')
                ->schema([Textarea::make('internal_note')->required()->rows(4)])
                ->action(fn (array $data, ServiceTicket $record) => app(ServiceTicketService::class)->updateWorkflow($record, auth()->user(), $data)),
            DeleteAction::make(),
        ];
    }
}
