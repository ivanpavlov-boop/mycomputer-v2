<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;
use JsonSerializable;

final readonly class SupplierOfferReappearancePreviewResult implements JsonSerializable
{
    /** @param array<int, string> $reasonCodes */
    public function __construct(
        public string $supplierKey,
        public string $supplierSkuHash,
        public string $previousPresenceStatus,
        public string $nextPresenceStatus,
        public CarbonImmutable $evaluatedAt,
        public bool $reactivationEligible,
        public bool $wouldReactivateSupplierOffer,
        public bool $requiresHumanReview,
        public array $reasonCodes,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier offer reappearance preview result');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'consecutive_missing_count' => 0,
            'evaluated_at' => $this->evaluatedAt->toAtomString(),
            'first_missing_at' => null,
            'next_presence_status' => $this->nextPresenceStatus,
            'previous_presence_status' => $this->previousPresenceStatus,
            'reactivation_eligible' => $this->reactivationEligible,
            'reason_codes' => $this->reasonCodes,
            'records_changed' => 0,
            'requires_human_review' => $this->requiresHumanReview,
            'supplier_key' => $this->supplierKey,
            'supplier_sku_hash' => $this->supplierSkuHash,
            'would_reactivate_supplier_offer' => $this->wouldReactivateSupplierOffer,
            'write_allowed' => false,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
