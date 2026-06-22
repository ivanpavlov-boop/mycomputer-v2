<?php

namespace App\Filament\Resources\CatalogSyncBatches\Pages;

use App\Filament\Resources\CatalogSyncBatches\CatalogSyncBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListCatalogSyncBatches extends ListRecords
{
    protected static string $resource = CatalogSyncBatchResource::class;
}
