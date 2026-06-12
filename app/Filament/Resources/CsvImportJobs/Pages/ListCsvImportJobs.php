<?php

namespace App\Filament\Resources\CsvImportJobs\Pages;

use App\Filament\Resources\CsvImportJobs\CsvImportJobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCsvImportJobs extends ListRecords
{
    protected static string $resource = CsvImportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
