<?php

namespace App\Contracts\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierAvailabilityMappingResult;

interface SupplierAvailabilityMapper
{
    public function supplierKey(): string;

    public function map(int|string|float $rawQuantityObserved, int|string|float $eolFlag): SupplierAvailabilityMappingResult;
}
