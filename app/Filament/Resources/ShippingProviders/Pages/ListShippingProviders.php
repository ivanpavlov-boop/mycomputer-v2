<?php

namespace App\Filament\Resources\ShippingProviders\Pages;

use App\Filament\Resources\ShippingProviders\ShippingProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShippingProviders extends ListRecords
{
    protected static string $resource = ShippingProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
