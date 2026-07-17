<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class CatalogOfferAggregationResult implements JsonSerializable
{
    public function __construct(
        public string $productReferenceHash,
        public int $totalOfferCount,
        public int $validActiveOfferCount,
        public int $inactiveOfferCount,
        public int $blockedOfferCount,
        public ?string $selectedCanonicalPublicStatus,
        public bool $hasActiveCommercialOffer,
        public bool $productShouldBeSellable,
        public bool $productVisibilityTransitionEligible,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'catalog offer aggregation result');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'blocked_offer_count' => $this->blockedOfferCount,
            'has_active_commercial_offer' => $this->hasActiveCommercialOffer,
            'inactive_offer_count' => $this->inactiveOfferCount,
            'product_reference_hash' => $this->productReferenceHash,
            'product_should_be_sellable' => $this->productShouldBeSellable,
            'product_visibility_transition_eligible' => $this->productVisibilityTransitionEligible,
            'records_changed' => 0,
            'selected_canonical_public_status' => $this->selectedCanonicalPublicStatus,
            'total_offer_count' => $this->totalOfferCount,
            'valid_active_offer_count' => $this->validActiveOfferCount,
            'write_allowed' => false,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
