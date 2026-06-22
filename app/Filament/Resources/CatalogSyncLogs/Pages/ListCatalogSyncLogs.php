<?php

namespace App\Filament\Resources\CatalogSyncLogs\Pages;

use App\Filament\Resources\CatalogSyncLogs\CatalogSyncLogResource;
use Filament\Resources\Pages\ListRecords;

class ListCatalogSyncLogs extends ListRecords
{
    protected static string $resource = CatalogSyncLogResource::class;
}
