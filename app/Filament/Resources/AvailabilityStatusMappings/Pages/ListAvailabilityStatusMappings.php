<?php

namespace App\Filament\Resources\AvailabilityStatusMappings\Pages;

use App\Filament\Resources\AvailabilityStatusMappings\AvailabilityStatusMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAvailabilityStatusMappings extends ListRecords
{
    protected static string $resource = AvailabilityStatusMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
