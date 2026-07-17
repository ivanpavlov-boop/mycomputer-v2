<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;

final readonly class SupplierOfferPresenceObservation
{
    public function __construct(
        public string $supplierKey,
        public string $supplierSkuHash,
        public string $previousPresenceStatus,
        public int $previousConsecutiveMissingCount,
        public ?CarbonImmutable $previousFirstMissingAt,
        public CarbonImmutable $evaluatedAt,
        public bool $isPresentInSnapshot,
    ) {}
}
