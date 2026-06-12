<?php

namespace App\Filament\Resources\EmailAutomations\Pages;

use App\Filament\Resources\EmailAutomations\EmailAutomationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailAutomations extends ListRecords
{
    protected static string $resource = EmailAutomationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
