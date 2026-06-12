<?php

namespace App\Filament\Resources\RewardVouchers\Pages;

use App\Filament\Resources\RewardVouchers\RewardVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRewardVouchers extends ListRecords
{
    protected static string $resource = RewardVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
