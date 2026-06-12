<?php

namespace App\Filament\Resources\CompatibilityRules\Pages;

use App\Filament\Resources\CompatibilityRules\CompatibilityRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompatibilityRule extends EditRecord
{
    protected static string $resource = CompatibilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
