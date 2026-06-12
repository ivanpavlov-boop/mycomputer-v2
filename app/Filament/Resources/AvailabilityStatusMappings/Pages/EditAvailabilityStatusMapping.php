<?php

namespace App\Filament\Resources\AvailabilityStatusMappings\Pages;

use App\Filament\Resources\AvailabilityStatusMappings\AvailabilityStatusMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAvailabilityStatusMapping extends EditRecord
{
    protected static string $resource = AvailabilityStatusMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
