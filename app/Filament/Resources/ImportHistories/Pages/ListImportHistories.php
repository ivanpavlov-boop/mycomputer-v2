<?php

namespace App\Filament\Resources\ImportHistories\Pages;

use App\Filament\Resources\ImportHistories\ImportHistoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportHistories extends ListRecords
{
    protected static string $resource = ImportHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
