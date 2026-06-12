<?php

namespace App\Filament\Resources\EmailSubscribers\Pages;

use App\Filament\Resources\EmailSubscribers\EmailSubscriberResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailSubscriber extends EditRecord
{
    protected static string $resource = EmailSubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
