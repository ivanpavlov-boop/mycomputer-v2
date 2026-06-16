<?php

namespace App\Filament\Resources\SupplierExclusionRules\Pages;

use App\Filament\Resources\SupplierExclusionRules\SupplierExclusionRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierExclusionRules extends ListRecords
{
    protected static string $resource = SupplierExclusionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
