<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationInput;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationResult;

final class SupplierSnapshotQualificationPolicy
{
    public const POLICY_KEY = 'supplier-full-snapshot-qualification-policy-v1';

    public function qualify(SupplierSnapshotQualificationInput $input): SupplierSnapshotQualificationResult
    {
        $isCountSafe = $input->productCount >= $input->minimumProductCount;
        $isDropSafe = $input->productDropPercent <= $input->maximumProductDropPercent;
        $freezeReasons = array_values(array_filter([
            ! $input->isSuccessful ? 'snapshot_not_successful' : null,
            ! $input->isFullSnapshot ? 'snapshot_not_full' : null,
            ! $input->isSchemaValid ? 'snapshot_schema_invalid' : null,
            $input->isTruncated ? 'snapshot_truncated' : null,
            ! $isCountSafe ? 'minimum_product_count_not_met' : null,
            ! $isDropSafe ? 'maximum_product_drop_exceeded' : null,
            $input->hasFatalBlocker ? 'fatal_source_integrity_blocker' : null,
            ! $input->supplierIdentityConfirmed ? 'supplier_identity_unconfirmed' : null,
            blank($input->snapshotFingerprint) ? 'snapshot_fingerprint_missing' : null,
            $input->isDuplicateFingerprint ? 'duplicate_snapshot_fingerprint' : null,
        ]));
        sort($freezeReasons, SORT_STRING);

        return new SupplierSnapshotQualificationResult(
            supplierKey: $input->supplierKey,
            snapshotId: $input->snapshotId,
            snapshotStatus: $input->snapshotStatus,
            observedAt: $input->observedAt,
            isSuccessful: $input->isSuccessful,
            isFullSnapshot: $input->isFullSnapshot,
            isSchemaValid: $input->isSchemaValid,
            isCountSafe: $isCountSafe,
            isDropSafe: $isDropSafe,
            qualifiesForPresenceTracking: $freezeReasons === [],
            freezeReasonCodes: $freezeReasons,
            requiresHumanReview: $freezeReasons !== [],
        );
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'policy_key' => self::POLICY_KEY,
            'requires_successful_processing' => true,
            'requires_full_snapshot' => true,
            'requires_schema_and_identifier_validation' => true,
            'requires_non_truncated_source' => true,
            'requires_minimum_product_count' => true,
            'requires_safe_product_drop' => true,
            'requires_no_fatal_blocker' => true,
            'requires_confirmed_supplier_identity' => true,
            'requires_unique_snapshot_fingerprint' => true,
            'frozen_snapshots_advance_lifecycle' => false,
            'write_allowed' => false,
        ];
    }
}
