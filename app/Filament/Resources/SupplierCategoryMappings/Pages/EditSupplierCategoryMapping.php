<?php

namespace App\Filament\Resources\SupplierCategoryMappings\Pages;

use App\Filament\Resources\SupplierCategoryMappings\SupplierCategoryMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierCategoryMapping extends EditRecord
{
    protected static string $resource = SupplierCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Изтрий'),
        ];
    }
}
