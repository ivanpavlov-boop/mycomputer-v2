<?php

namespace App\Filament\Resources\AttributeAliases\Pages;

use App\Filament\Resources\AttributeAliases\AttributeAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributeAliases extends ListRecords
{
    protected static string $resource = AttributeAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
