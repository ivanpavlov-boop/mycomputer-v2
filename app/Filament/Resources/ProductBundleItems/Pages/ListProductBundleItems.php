<?php

namespace App\Filament\Resources\ProductBundleItems\Pages;

use App\Filament\Resources\ProductBundleItems\ProductBundleItemResource;
use Filament\Resources\Pages\ListRecords;

class ListProductBundleItems extends ListRecords
{
    protected static string $resource = ProductBundleItemResource::class;
}
