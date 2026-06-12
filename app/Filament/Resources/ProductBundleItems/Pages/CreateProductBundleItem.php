<?php

namespace App\Filament\Resources\ProductBundleItems\Pages;

use App\Filament\Resources\ProductBundleItems\ProductBundleItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductBundleItem extends CreateRecord
{
    protected static string $resource = ProductBundleItemResource::class;
}
