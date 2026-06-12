<?php

namespace App\Filament\Resources\CsvExportJobs\Pages;

use App\Filament\Resources\CsvExportJobs\CsvExportJobResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCsvExportJob extends EditRecord
{
    protected static string $resource = CsvExportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
