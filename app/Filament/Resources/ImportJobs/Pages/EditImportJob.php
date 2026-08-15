<?php

namespace App\Filament\Resources\ImportJobs\Pages;

use App\Filament\Resources\ImportJobs\ImportJobResource;
use App\Models\ImportJob;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImportJob extends EditRecord
{
    protected static string $resource = ImportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (ImportJob $record): bool => ImportJobResource::canDelete($record)),
        ];
    }
}
