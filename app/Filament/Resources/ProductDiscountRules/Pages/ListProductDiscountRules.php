<?php

namespace App\Filament\Resources\ProductDiscountRules\Pages;

use App\Filament\Resources\ProductDiscountRules\ProductDiscountRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductDiscountRules extends ListRecords
{
    protected static string $resource = ProductDiscountRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
