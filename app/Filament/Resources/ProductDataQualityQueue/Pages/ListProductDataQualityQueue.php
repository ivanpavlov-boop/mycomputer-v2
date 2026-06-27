<?php

namespace App\Filament\Resources\ProductDataQualityQueue\Pages;

use App\Filament\Resources\ProductDataQualityQueue\ProductDataQualityQueueResource;
use App\Filament\Resources\ProductDataQualityQueue\Widgets\ProductDataQualityQueueStats;
use Filament\Resources\Pages\ListRecords;

class ListProductDataQualityQueue extends ListRecords
{
    protected static string $resource = ProductDataQualityQueueResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProductDataQualityQueueStats::class,
        ];
    }
}
