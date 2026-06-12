<?php

namespace App\Filament\Resources\AbandonedCartRecords\Pages;

use App\Filament\Resources\AbandonedCartRecords\AbandonedCartRecordResource;
use Filament\Resources\Pages\ListRecords;

class ListAbandonedCartRecords extends ListRecords
{
    protected static string $resource = AbandonedCartRecordResource::class;
}
