<?php

namespace App\Filament\Resources\ProductQualityFlags\Pages;

use App\Filament\Resources\ProductQualityFlags\ProductQualityFlagResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductQualityFlag extends EditRecord
{
    protected static string $resource = ProductQualityFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResourceUrl();
    }
}
