<?php

namespace App\Filament\Resources\LoyaltyTransactions\Pages;

use App\Filament\Resources\LoyaltyTransactions\LoyaltyTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListLoyaltyTransactions extends ListRecords
{
    protected static string $resource = LoyaltyTransactionResource::class;
}
