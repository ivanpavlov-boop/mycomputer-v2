<?php

namespace App\Filament\Resources\SupplierCategoryMappings\Pages;

use App\Filament\Resources\SupplierCategoryMappings\SupplierCategoryMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierCategoryMappings extends ListRecords
{
    protected static string $resource = SupplierCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Създай картографиране'),
        ];
    }
}
