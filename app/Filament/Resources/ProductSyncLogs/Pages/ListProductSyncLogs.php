<?php

namespace App\Filament\Resources\ProductSyncLogs\Pages;

use App\Filament\Resources\ProductSyncLogs\ProductSyncLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductSyncLogs extends ListRecords
{
    protected static string $resource = ProductSyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
