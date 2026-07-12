<?php

namespace App\Contracts\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\AvailabilityNormalizationResult;

interface AvailabilityNormalizationInterface
{
    public const CONTRACT_VERSION = 'supplier-availability-normalization-v1';

    /** @param array<string, string> $mapping */
    public function normalize(
        ?string $externalCode,
        ?string $externalLabel,
        ?int $quantity,
        array $mapping = [],
        string $profileVersion = 'unknown',
    ): AvailabilityNormalizationResult;
}
