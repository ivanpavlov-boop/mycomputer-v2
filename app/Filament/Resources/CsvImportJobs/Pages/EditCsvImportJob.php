<?php

namespace App\Filament\Resources\CsvImportJobs\Pages;

use App\Filament\Resources\CsvImportJobs\CsvImportJobResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCsvImportJob extends EditRecord
{
    protected static string $resource = CsvImportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
