<?php

namespace App\Filament\Resources\CategoryProductAttributes\Pages;

use App\Filament\Resources\CategoryProductAttributes\CategoryProductAttributeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategoryProductAttribute extends EditRecord
{
    protected static string $resource = CategoryProductAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
