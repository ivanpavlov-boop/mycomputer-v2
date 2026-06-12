<?php

namespace App\Filament\Resources\ProductSyncLogs\Pages;

use App\Filament\Resources\ProductSyncLogs\ProductSyncLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductSyncLog extends CreateRecord
{
    protected static string $resource = ProductSyncLogResource::class;
}
