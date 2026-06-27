<?php

namespace App\Filament\Resources\ProductQualityFlags\Pages;

use App\Filament\Resources\ProductQualityFlags\ProductQualityFlagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductQualityFlag extends CreateRecord
{
    protected static string $resource = ProductQualityFlagResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl();
    }
}
