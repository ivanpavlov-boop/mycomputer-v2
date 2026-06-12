<?php

namespace App\Filament\Resources\AttributeValueAliases\Pages;

use App\Filament\Resources\AttributeValueAliases\AttributeValueAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributeValueAliases extends ListRecords
{
    protected static string $resource = AttributeValueAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
