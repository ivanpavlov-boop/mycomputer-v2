<?php

namespace App\Filament\Resources\ProductQualityFlags\Pages;

use App\Filament\Resources\ProductQualityFlags\ProductQualityFlagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductQualityFlag extends CreateRecord
{
    protected static string $resource = ProductQualityFlagResource::class;

    public function getTitle(): string
    {
        return 'Създаване на флаг за качество';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl();
    }
}
