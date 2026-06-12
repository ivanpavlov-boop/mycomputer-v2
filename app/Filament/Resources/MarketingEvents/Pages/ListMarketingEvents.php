<?php

namespace App\Filament\Resources\MarketingEvents\Pages;

use App\Filament\Resources\MarketingEvents\MarketingEventResource;
use Filament\Resources\Pages\ListRecords;

class ListMarketingEvents extends ListRecords
{
    protected static string $resource = MarketingEventResource::class;
}
