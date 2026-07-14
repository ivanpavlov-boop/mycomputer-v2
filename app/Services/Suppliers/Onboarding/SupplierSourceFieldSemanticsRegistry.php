<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierSourceFieldSemanticsProfile;

final class SupplierSourceFieldSemanticsRegistry
{
    public function find(string $key): ?SupplierSourceFieldSemanticsProfile
    {
        return match ($key) {
            'apcom-official-v1' => SupplierSourceFieldSemanticsProfile::apcomOfficialV1(),
            'apcom-observed-stock-v1' => SupplierSourceFieldSemanticsProfile::apcomObservedStockV1(),
            default => null,
        };
    }
}
