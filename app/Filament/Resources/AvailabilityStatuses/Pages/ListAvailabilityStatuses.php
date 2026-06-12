<?php

namespace App\Filament\Resources\AvailabilityStatuses\Pages;

use App\Filament\Resources\AvailabilityStatuses\AvailabilityStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAvailabilityStatuses extends ListRecords
{
    protected static string $resource = AvailabilityStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
