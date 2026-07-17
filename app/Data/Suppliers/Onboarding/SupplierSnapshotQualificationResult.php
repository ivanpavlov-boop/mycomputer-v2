<?php

namespace App\Data\Suppliers\Onboarding;

use Carbon\CarbonImmutable;
use JsonSerializable;

final readonly class SupplierSnapshotQualificationResult implements JsonSerializable
{
    /** @param array<int, string> $freezeReasonCodes */
    public function __construct(
        public string $supplierKey,
        public string $snapshotId,
        public string $snapshotStatus,
        public CarbonImmutable $observedAt,
        public bool $isSuccessful,
        public bool $isFullSnapshot,
        public bool $isSchemaValid,
        public bool $isCountSafe,
        public bool $isDropSafe,
        public bool $qualifiesForPresenceTracking,
        public array $freezeReasonCodes,
        public bool $requiresHumanReview,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier snapshot qualification result');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'freeze_reason_codes' => $this->freezeReasonCodes,
            'is_count_safe' => $this->isCountSafe,
            'is_drop_safe' => $this->isDropSafe,
            'is_full_snapshot' => $this->isFullSnapshot,
            'is_schema_valid' => $this->isSchemaValid,
            'is_successful' => $this->isSuccessful,
            'observed_at' => $this->observedAt->toAtomString(),
            'qualifies_for_presence_tracking' => $this->qualifiesForPresenceTracking,
            'requires_human_review' => $this->requiresHumanReview,
            'snapshot_id' => $this->snapshotId,
            'snapshot_status' => $this->snapshotStatus,
            'supplier_key' => $this->supplierKey,
            'write_allowed' => false,
            'records_changed' => 0,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
