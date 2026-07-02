<?php

namespace App\Filament\Resources\CategoryProductAttributes\Pages;

use App\Filament\Resources\CategoryProductAttributes\CategoryProductAttributeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategoryProductAttributes extends ListRecords
{
    protected static string $resource = CategoryProductAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
