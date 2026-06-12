<?php

namespace App\Filament\Resources\ProductBundleOptions\Pages;

use App\Filament\Resources\ProductBundleOptions\ProductBundleOptionResource;
use Filament\Resources\Pages\ListRecords;

class ListProductBundleOptions extends ListRecords
{
    protected static string $resource = ProductBundleOptionResource::class;
}
