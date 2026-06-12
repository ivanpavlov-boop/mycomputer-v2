<?php

namespace App\Filament\Resources\FailedImports\Pages;

use App\Filament\Resources\FailedImports\FailedImportResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFailedImport extends CreateRecord
{
    protected static string $resource = FailedImportResource::class;
}
