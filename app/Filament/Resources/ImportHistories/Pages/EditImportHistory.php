<?php

namespace App\Filament\Resources\ImportHistories\Pages;

use App\Filament\Resources\ImportHistories\ImportHistoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImportHistory extends EditRecord
{
    protected static string $resource = ImportHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
