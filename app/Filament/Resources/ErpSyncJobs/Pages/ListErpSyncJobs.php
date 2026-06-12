<?php

namespace App\Filament\Resources\ErpSyncJobs\Pages;

use App\Filament\Resources\ErpSyncJobs\ErpSyncJobResource;
use Filament\Resources\Pages\ListRecords;

class ListErpSyncJobs extends ListRecords
{
    protected static string $resource = ErpSyncJobResource::class;
}
