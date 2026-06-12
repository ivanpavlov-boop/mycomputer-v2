<?php

namespace App\Filament\Resources\QuoteRequestItems\Pages;

use App\Filament\Resources\QuoteRequestItems\QuoteRequestItemResource;
use Filament\Resources\Pages\ListRecords;

class ListQuoteRequestItems extends ListRecords
{
    protected static string $resource = QuoteRequestItemResource::class;
}
