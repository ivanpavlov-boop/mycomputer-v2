<?php

namespace App\Data\Suppliers\Snapshots;

use App\Data\Suppliers\Onboarding\SnapshotSourceIdentity;
use InvalidArgumentException;

final readonly class CanonicalSupplierSnapshotGenerationHeader
{
    private const FIELDS = [
        'supplier_id',
        'supplier_key',
        'supplier_feed_id',
        'supplier_import_execution_claim_id',
        'import_history_id',
        'predecessor_snapshot_generation_id',
        'schema_version',
        'producer_version',
        'qualification_policy_key',
        'capture_integrity_policy_key',
        'policy_versions',
        'freshness_policy_key',
        'freshness_max_age_hours',
        'freshness_policy_approved',
        'source_identity',
        'source_fingerprint',
        'captured_at',
        'authoritative_snapshot_at',
        'capture_started_at',
        'capture_completed_at',
        'capture_outcome',
        'capture_failure_reason_code',
        'qualification_state',
        'qualification_reason_codes',
        'successful',
        'full',
        'schema_valid',
        'truncated',
        'fatal_integrity_blocker',
        'supplier_identity_confirmed',
        'comparable',
        'total_observed_count',
        'valid_observation_count',
        'invalid_observation_count',
        'rejected_observation_count',
        'duplicate_observation_count',
        'enrolled_observation_count',
        'minimum_product_count',
        'product_drop_percent',
        'maximum_product_drop_percent',
        'cohort_fingerprint',
        'observation_set_fingerprint',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        CanonicalSupplierContract::positiveInteger($values['supplier_id'], 'supplier_id');
        self::supplierKey($values['supplier_key']);
        CanonicalSupplierContract::positiveInteger($values['supplier_feed_id'], 'supplier_feed_id');
        CanonicalSupplierContract::positiveInteger(
            $values['supplier_import_execution_claim_id'],
            'supplier_import_execution_claim_id',
        );
        CanonicalSupplierContract::positiveInteger($values['import_history_id'], 'import_history_id');
        CanonicalSupplierContract::nullablePositiveInteger(
            $values['predecessor_snapshot_generation_id'],
            'predecessor_snapshot_generation_id',
        );
        CanonicalSupplierContract::asciiString($values['schema_version'], 'schema_version', 96);
        CanonicalSupplierContract::asciiString($values['producer_version'], 'producer_version', 96);
        CanonicalSupplierContract::asciiString(
            $values['qualification_policy_key'],
            'qualification_policy_key',
            96,
        );
        CanonicalSupplierContract::asciiString(
            $values['capture_integrity_policy_key'],
            'capture_integrity_policy_key',
            96,
        );
        CanonicalSupplierContract::canonicalObject($values['policy_versions'], 'policy_versions');
        self::nullableAscii($values['freshness_policy_key'], 'freshness_policy_key', 96);
        CanonicalSupplierContract::nullableUnsignedInteger(
            $values['freshness_max_age_hours'],
            'freshness_max_age_hours',
        );
        CanonicalSupplierContract::boolean($values['freshness_policy_approved'], 'freshness_policy_approved');
        SnapshotSourceIdentity::validate($values['source_identity']);
        CanonicalSupplierContract::hex64($values['source_fingerprint'], 'source_fingerprint');
        CanonicalSupplierContract::snapshotUtcSeconds($values['captured_at'], 'captured_at');
        CanonicalSupplierContract::nullableSnapshotUtcSeconds(
            $values['authoritative_snapshot_at'],
            'authoritative_snapshot_at',
        );
        CanonicalSupplierContract::snapshotUtcSeconds($values['capture_started_at'], 'capture_started_at');
        CanonicalSupplierContract::snapshotUtcSeconds($values['capture_completed_at'], 'capture_completed_at');
        CanonicalSupplierContract::enum($values['capture_outcome'], 'capture_outcome', [
            'completed',
            'completed_with_errors',
            'failed',
            'incomplete',
            'overflow',
        ]);
        self::nullableAscii($values['capture_failure_reason_code'], 'capture_failure_reason_code', 96);
        CanonicalSupplierContract::enum($values['qualification_state'], 'qualification_state', [
            'qualified_baseline',
            'qualified_comparable',
            'frozen',
        ]);
        $values['qualification_reason_codes'] = CanonicalSupplierContract::sortedUniqueAsciiStrings(
            $values['qualification_reason_codes'],
            'qualification_reason_codes',
            96,
        );
        CanonicalSupplierSnapshotReasonCode::assertAllApproved($values['qualification_reason_codes']);

        foreach ([
            'successful',
            'full',
            'schema_valid',
            'truncated',
            'fatal_integrity_blocker',
            'supplier_identity_confirmed',
            'comparable',
        ] as $field) {
            CanonicalSupplierContract::boolean($values[$field], $field);
        }

        foreach ([
            'total_observed_count',
            'valid_observation_count',
            'invalid_observation_count',
            'rejected_observation_count',
            'duplicate_observation_count',
            'enrolled_observation_count',
        ] as $field) {
            CanonicalSupplierContract::unsignedInteger($values[$field], $field);
        }

        CanonicalSupplierContract::positiveInteger($values['minimum_product_count'], 'minimum_product_count');
        CanonicalSupplierContract::exactPercent($values['product_drop_percent'], 'product_drop_percent');
        CanonicalSupplierContract::unsignedInteger(
            $values['maximum_product_drop_percent'],
            'maximum_product_drop_percent',
            100,
        );
        CanonicalSupplierContract::nullableHex64($values['cohort_fingerprint'], 'cohort_fingerprint');
        CanonicalSupplierContract::nullableHex64(
            $values['observation_set_fingerprint'],
            'observation_set_fingerprint',
        );

        self::assertCrossFieldContract($values);

        return new self($values);
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /** @return array<string, mixed> */
    public function toCanonicalArray(): array
    {
        return $this->values;
    }

    public function canonicalBytes(): string
    {
        return CanonicalSupplierContract::encodeSorted($this->values);
    }

    private static function supplierKey(mixed $value): string
    {
        $key = CanonicalSupplierContract::asciiString($value, 'supplier_key', 96);

        if ($key !== strtolower(trim($key))) {
            throw new InvalidArgumentException('invalid_supplier_key');
        }

        return $key;
    }

    private static function nullableAscii(mixed $value, string $field, int $maxBytes): ?string
    {
        return $value === null ? null : CanonicalSupplierContract::asciiString($value, $field, $maxBytes);
    }

    /** @param array<string, mixed> $values */
    private static function assertCrossFieldContract(array $values): void
    {
        $freshnessAbsent = $values['freshness_policy_key'] === null
            && $values['freshness_max_age_hours'] === null
            && $values['freshness_policy_approved'] === false;
        $freshnessApproved = $values['freshness_policy_key'] !== null
            && $values['freshness_max_age_hours'] !== null
            && $values['freshness_policy_approved'] === true;

        if (! $freshnessAbsent && ! $freshnessApproved) {
            throw new InvalidArgumentException('invalid_freshness_tuple');
        }

        if ($values['captured_at'] !== $values['capture_completed_at']
            || $values['capture_started_at'] > $values['capture_completed_at']
            || ($values['authoritative_snapshot_at'] !== null
                && $values['authoritative_snapshot_at'] > $values['captured_at'])) {
            throw new InvalidArgumentException('invalid_capture_chronology');
        }

        if ($values['total_observed_count'] !== $values['valid_observation_count']
                + $values['invalid_observation_count']
                + $values['rejected_observation_count']
                + $values['duplicate_observation_count']
            || $values['enrolled_observation_count'] < $values['valid_observation_count']) {
            throw new InvalidArgumentException('invalid_observation_counts');
        }

        if ($values['product_drop_percent'] !== null
            && (int) str_replace('.', '', $values['product_drop_percent'])
                > $values['maximum_product_drop_percent'] * 1_000_000) {
            throw new InvalidArgumentException('invalid_product_drop_threshold');
        }

        $qualification = $values['qualification_state'];
        if ($qualification === 'frozen') {
            if ($values['qualification_reason_codes'] === []) {
                throw new InvalidArgumentException('invalid_qualification_tuple');
            }

            return;
        }

        $sharedQualified = $values['qualification_reason_codes'] === []
            && $values['successful']
            && $values['full']
            && $values['schema_valid']
            && ! $values['truncated']
            && ! $values['fatal_integrity_blocker']
            && $values['supplier_identity_confirmed']
            && $values['valid_observation_count'] >= $values['minimum_product_count']
            && $values['cohort_fingerprint'] !== null
            && $values['observation_set_fingerprint'] !== null;

        $baseline = $qualification === 'qualified_baseline'
            && $values['predecessor_snapshot_generation_id'] === null
            && ! $values['comparable']
            && $values['product_drop_percent'] === null;
        $comparable = $qualification === 'qualified_comparable'
            && $values['predecessor_snapshot_generation_id'] !== null
            && $values['comparable']
            && $values['product_drop_percent'] !== null;

        if (! $sharedQualified || (! $baseline && ! $comparable)) {
            throw new InvalidArgumentException('invalid_qualification_tuple');
        }
    }
}
