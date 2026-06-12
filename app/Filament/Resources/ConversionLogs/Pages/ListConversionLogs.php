<?php

namespace App\Filament\Resources\ConversionLogs\Pages;

use App\Filament\Resources\ConversionLogs\ConversionLogResource;
use Filament\Resources\Pages\ListRecords;

class ListConversionLogs extends ListRecords
{
    protected static string $resource = ConversionLogResource::class;
}
