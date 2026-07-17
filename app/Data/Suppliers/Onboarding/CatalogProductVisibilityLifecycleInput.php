<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;

final readonly class CatalogProductVisibilityLifecycleInput
{
    public function __construct(
        public string $productReferenceHash,
        public ?CarbonImmutable $zeroActiveOffersSince,
        public CarbonImmutable $evaluatedAt,
        public bool $hasActiveCommercialOffer,
        public ?string $canonicalPublicStatus = null,
    ) {}
}
