<?php

namespace App\Filament\Resources\EmailSubscribers\Pages;

use App\Filament\Resources\EmailSubscribers\EmailSubscriberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailSubscribers extends ListRecords
{
    protected static string $resource = EmailSubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
