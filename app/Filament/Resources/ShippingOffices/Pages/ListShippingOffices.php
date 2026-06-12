<?php

namespace App\Filament\Resources\ShippingOffices\Pages;

use App\Filament\Resources\ShippingOffices\ShippingOfficeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShippingOffices extends ListRecords
{
    protected static string $resource = ShippingOfficeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
