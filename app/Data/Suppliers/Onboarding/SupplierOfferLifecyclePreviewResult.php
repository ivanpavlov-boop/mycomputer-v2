<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;
use JsonSerializable;

final readonly class SupplierOfferLifecyclePreviewResult implements JsonSerializable
{
    /** @param array<int, string> $freezeReasonCodes */
    public function __construct(
        public string $supplierKey,
        public string $supplierSkuHash,
        public string $previousPresenceStatus,
        public string $nextPresenceStatus,
        public int $consecutiveMissingCount,
        public ?CarbonImmutable $firstMissingAt,
        public CarbonImmutable $evaluatedAt,
        public int $missingDurationHours,
        public int $missingSnapshotThreshold,
        public int $minimumMissingDurationHours,
        public bool $deactivationEligible,
        public bool $reactivationEligible,
        public bool $wouldDeactivateSupplierOffer,
        public bool $wouldReactivateSupplierOffer,
        public bool $requiresAvailabilityConfirmation,
        public bool $requiresHumanReview,
        public array $freezeReasonCodes = [],
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier offer lifecycle preview result');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'consecutive_missing_count' => $this->consecutiveMissingCount,
            'deactivation_eligible' => $this->deactivationEligible,
            'evaluated_at' => $this->evaluatedAt->toAtomString(),
            'first_missing_at' => $this->firstMissingAt?->toAtomString(),
            'freeze_reason_codes' => $this->freezeReasonCodes,
            'minimum_missing_duration_hours' => $this->minimumMissingDurationHours,
            'missing_duration_hours' => $this->missingDurationHours,
            'missing_snapshot_threshold' => $this->missingSnapshotThreshold,
            'next_presence_status' => $this->nextPresenceStatus,
            'previous_presence_status' => $this->previousPresenceStatus,
            'reactivation_eligible' => $this->reactivationEligible,
            'records_changed' => 0,
            'requires_availability_confirmation' => $this->requiresAvailabilityConfirmation,
            'requires_human_review' => $this->requiresHumanReview,
            'supplier_key' => $this->supplierKey,
            'supplier_sku_hash' => $this->supplierSkuHash,
            'would_deactivate_supplier_offer' => $this->wouldDeactivateSupplierOffer,
            'would_reactivate_supplier_offer' => $this->wouldReactivateSupplierOffer,
            'write_allowed' => false,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
