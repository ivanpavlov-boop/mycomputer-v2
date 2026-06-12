<?php

namespace App\Filament\Resources\SupplierFeeds\Pages;

use App\Filament\Resources\SupplierFeeds\SupplierFeedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierFeeds extends ListRecords
{
    protected static string $resource = SupplierFeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
