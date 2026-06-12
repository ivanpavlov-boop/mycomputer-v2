<?php

namespace App\Filament\Resources\CanonicalAttributeValues\Pages;

use App\Filament\Resources\CanonicalAttributeValues\CanonicalAttributeValueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCanonicalAttributeValues extends ListRecords
{
    protected static string $resource = CanonicalAttributeValueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
