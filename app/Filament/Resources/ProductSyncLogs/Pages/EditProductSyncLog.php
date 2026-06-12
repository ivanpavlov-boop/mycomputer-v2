<?php

namespace App\Filament\Resources\ProductSyncLogs\Pages;

use App\Filament\Resources\ProductSyncLogs\ProductSyncLogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductSyncLog extends EditRecord
{
    protected static string $resource = ProductSyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
