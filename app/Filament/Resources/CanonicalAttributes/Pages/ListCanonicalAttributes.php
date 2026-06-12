<?php

namespace App\Filament\Resources\CanonicalAttributes\Pages;

use App\Filament\Resources\CanonicalAttributes\CanonicalAttributeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCanonicalAttributes extends ListRecords
{
    protected static string $resource = CanonicalAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
