<?php

namespace App\Data\Suppliers\Onboarding;

final readonly class CatalogOfferAggregationInput
{
    /** @param array<int, array{canonical_public_status: string, valid: bool, blocked?: bool}> $offers */
    public function __construct(
        public string $productReferenceHash,
        public array $offers,
    ) {}
}
