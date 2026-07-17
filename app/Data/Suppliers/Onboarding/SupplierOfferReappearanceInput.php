<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;

final readonly class SupplierOfferReappearanceInput
{
    public function __construct(
        public string $supplierKey,
        public string $supplierSkuHash,
        public string $previousPresenceStatus,
        public CarbonImmutable $evaluatedAt,
        public bool $supplierSkuMatchesExactly,
        public float $price,
        public bool $supplierMapperValid,
        public bool $hasIdentifierConflict,
        public bool $hasBlockingValidationIssue,
    ) {}
}
