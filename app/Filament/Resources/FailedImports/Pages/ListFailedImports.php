<?php

namespace App\Filament\Resources\FailedImports\Pages;

use App\Filament\Resources\FailedImports\FailedImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFailedImports extends ListRecords
{
    protected static string $resource = FailedImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
