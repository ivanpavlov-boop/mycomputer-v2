<?php

namespace App\Filament\Resources\SupplierExclusionRules\Pages;

use App\Filament\Resources\SupplierExclusionRules\SupplierExclusionRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierExclusionRule extends EditRecord
{
    protected static string $resource = SupplierExclusionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
