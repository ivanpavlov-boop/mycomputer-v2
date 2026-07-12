<?php

namespace App\Contracts\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\PriceNormalizationResult;

interface PriceNormalizationInterface
{
    public const CONTRACT_VERSION = 'supplier-price-normalization-v1';

    public function normalize(
        mixed $rawSupplierPrice,
        string $sourceCurrency,
        string $normalizedCurrency = 'EUR',
        string $taxInterpretation = 'profile-defined',
    ): PriceNormalizationResult;
}
