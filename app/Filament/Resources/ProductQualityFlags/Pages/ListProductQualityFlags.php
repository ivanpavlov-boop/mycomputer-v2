<?php

namespace App\Filament\Resources\ProductQualityFlags\Pages;

use App\Filament\Resources\ProductQualityFlags\ProductQualityFlagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductQualityFlags extends ListRecords
{
    protected static string $resource = ProductQualityFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Създаване на флаг за качество'),
        ];
    }
}
