<?php

namespace App\Filament\Resources\CanonicalProductFamilies\Pages;

use App\Filament\Resources\CanonicalProductFamilies\CanonicalProductFamilyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCanonicalProductFamilies extends ListRecords
{
    protected static string $resource = CanonicalProductFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Създай семейство'),
        ];
    }
}
