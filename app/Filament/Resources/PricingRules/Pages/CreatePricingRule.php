<?php

namespace App\Filament\Resources\PricingRules\Pages;

use App\Filament\Resources\PricingRules\PricingRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingRule extends CreateRecord
{
    protected static string $resource = PricingRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl();
    }
}
