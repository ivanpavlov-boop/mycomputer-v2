<?php

namespace App\Filament\Resources\CsvExportJobs\Pages;

use App\Filament\Resources\CsvExportJobs\CsvExportJobResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCsvExportJob extends CreateRecord
{
    protected static string $resource = CsvExportJobResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
