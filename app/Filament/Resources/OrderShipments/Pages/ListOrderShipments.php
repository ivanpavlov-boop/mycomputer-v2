<?php

namespace App\Filament\Resources\OrderShipments\Pages;

use App\Filament\Resources\OrderShipments\OrderShipmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrderShipments extends ListRecords
{
    protected static string $resource = OrderShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
