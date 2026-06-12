<?php

namespace App\Filament\Resources\RewardVouchers\Pages;

use App\Filament\Resources\RewardVouchers\RewardVoucherResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRewardVoucher extends EditRecord
{
    protected static string $resource = RewardVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
