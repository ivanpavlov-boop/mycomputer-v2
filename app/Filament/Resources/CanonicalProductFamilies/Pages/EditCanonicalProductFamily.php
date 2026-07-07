<?php

namespace App\Filament\Resources\CanonicalProductFamilies\Pages;

use App\Filament\Resources\CanonicalProductFamilies\CanonicalProductFamilyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCanonicalProductFamily extends EditRecord
{
    protected static string $resource = CanonicalProductFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Изтрий'),
        ];
    }
}
