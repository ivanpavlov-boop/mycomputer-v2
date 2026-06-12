<?php

namespace App\Filament\Resources\ImportJobs\Pages;

use App\Filament\Resources\ImportJobs\ImportJobResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImportJob extends EditRecord
{
    protected static string $resource = ImportJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
