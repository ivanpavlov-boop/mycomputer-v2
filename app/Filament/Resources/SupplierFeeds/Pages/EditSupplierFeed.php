<?php

namespace App\Filament\Resources\SupplierFeeds\Pages;

use App\Filament\Resources\SupplierFeeds\SupplierFeedResource;
use App\Models\SupplierFeed;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierFeed extends EditRecord
{
    protected static string $resource = SupplierFeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (SupplierFeed $record): bool => SupplierFeedResource::canDelete($record)),
        ];
    }
}
