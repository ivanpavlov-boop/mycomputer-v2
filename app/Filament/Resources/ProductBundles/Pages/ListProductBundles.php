<?php

namespace App\Filament\Resources\ProductBundles\Pages;

use App\Filament\Resources\ProductBundles\ProductBundleResource;
use Filament\Resources\Pages\ListRecords;

class ListProductBundles extends ListRecords
{
    protected static string $resource = ProductBundleResource::class;
}
