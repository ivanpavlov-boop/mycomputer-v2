<?php

namespace App\Filament\Resources\CsvImportJobs\Pages;

use App\Filament\Resources\CsvImportJobs\CsvImportJobResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCsvImportJob extends CreateRecord
{
    protected static string $resource = CsvImportJobResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        if (is_array($data['file_path'])) {
            $data['file_path'] = reset($data['file_path']);
        }

        $data['file_path'] = 'imports/'.ltrim((string) $data['file_path'], '/');
        $data['original_filename'] ??= basename($data['file_path']);

        return $data;
    }
}
