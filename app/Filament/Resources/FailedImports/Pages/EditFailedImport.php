<?php

namespace App\Filament\Resources\FailedImports\Pages;

use App\Filament\Resources\FailedImports\FailedImportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFailedImport extends EditRecord
{
    protected static string $resource = FailedImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
