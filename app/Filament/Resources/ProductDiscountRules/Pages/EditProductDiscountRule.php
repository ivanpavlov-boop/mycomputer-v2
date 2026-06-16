<?php

namespace App\Filament\Resources\ProductDiscountRules\Pages;

use App\Filament\Resources\ProductDiscountRules\ProductDiscountRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductDiscountRule extends EditRecord
{
    protected static string $resource = ProductDiscountRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
