<?php

namespace App\Filament\Resources\CompatibilityRules\Pages;

use App\Filament\Resources\CompatibilityRules\CompatibilityRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompatibilityRules extends ListRecords
{
    protected static string $resource = CompatibilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
