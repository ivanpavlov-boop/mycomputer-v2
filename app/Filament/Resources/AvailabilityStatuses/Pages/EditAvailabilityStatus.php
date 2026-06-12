<?php

namespace App\Filament\Resources\AvailabilityStatuses\Pages;

use App\Filament\Resources\AvailabilityStatuses\AvailabilityStatusResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAvailabilityStatus extends EditRecord
{
    protected static string $resource = AvailabilityStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
