<?php

namespace App\Data\Suppliers\Snapshots;

use InvalidArgumentException;

final class CanonicalSupplierSnapshotReasonCode
{
    public const V4_LIFECYCLE = [
        'duplicate_snapshot_fingerprint',
        'fatal_source_integrity_blocker',
        'maximum_product_drop_exceeded',
        'minimum_product_count_not_met',
        'snapshot_fingerprint_missing',
        'snapshot_not_full',
        'snapshot_not_successful',
        'snapshot_schema_invalid',
        'snapshot_truncated',
        'supplier_identity_unconfirmed',
    ];

    public const CAPTURE_INTEGRITY = [
        'capture_cohort_changed',
        'capture_cohort_incomplete',
        'capture_concurrent_import_activity',
        'capture_duplicate_conflict',
        'capture_generation_gap',
        'capture_identity_conflict',
        'capture_invalid_observation',
        'capture_observation_fingerprint_conflict',
        'capture_overflow',
        'capture_persistence_failure',
        'capture_rejected_observation',
        'capture_source_fingerprint_invalid',
        'capture_source_identity_invalid',
        'capture_truncated',
        'capture_unknown_activity',
        'capture_unknown_integrity_reason',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::CAPTURE_INTEGRITY, ...self::V4_LIFECYCLE];
    }

    /** @param list<string> $reasonCodes */
    public static function assertAllApproved(array $reasonCodes): void
    {
        foreach ($reasonCodes as $reasonCode) {
            if (! in_array($reasonCode, self::all(), true)) {
                throw new InvalidArgumentException('invalid_qualification_reason_codes');
            }
        }
    }
}
