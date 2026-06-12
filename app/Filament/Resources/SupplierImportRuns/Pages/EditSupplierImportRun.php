<?php

namespace App\Filament\Resources\SupplierImportRuns\Pages;

use App\Filament\Resources\SupplierImportRuns\SupplierImportRunResource;
use Filament\Resources\Pages\EditRecord;

class EditSupplierImportRun extends EditRecord
{
    protected static string $resource = SupplierImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
