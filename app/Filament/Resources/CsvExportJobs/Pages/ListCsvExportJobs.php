<?php

namespace App\Filament\Resources\CsvExportJobs\Pages;

use App\Filament\Resources\CsvExportJobs\CsvExportJobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCsvExportJobs extends ListRecords
{
    protected static string $resource = CsvExportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
