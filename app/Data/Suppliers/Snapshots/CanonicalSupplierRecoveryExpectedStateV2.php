<?php

namespace App\Data\Suppliers\Snapshots;

use InvalidArgumentException;

final readonly class CanonicalSupplierRecoveryExpectedStateV2 extends FixedOrderCanonicalJson
{
    public const SCHEMA = 'expected_state_fingerprint_v2';

    public const ACTIONS = [
        'republish_same_key',
        'recover_expired_queued_ownership',
        'terminalize_stale_dispatch',
        'terminalize_publication_mismatch',
        'terminalize_abandoned_processing',
    ];

    public const CLAIM_STATES = [
        'pending_dispatch',
        'queued',
        'processing',
        'terminal_qualified',
        'terminal_frozen',
        'terminal_failed',
    ];

    public const OUTBOX_STATES = [
        'pending',
        'leased',
        'published',
        'recovery_required',
        'terminal_failed',
    ];

    protected const DOMAIN = 'mycomputer:supplier-recovery-expected-state:v2';

    protected const FIELDS = [
        'schema',
        'authorization_action',
        'execution_claim_id',
        'dispatch_outbox_id',
        'logical_execution_key',
        'execution_path',
        'claim_state',
        'outbox_state',
        'supplier_id',
        'supplier_import_run_id',
        'supplier_feed_id',
        'import_job_id',
        'import_history_id',
        'publication_attempt_count',
        'delivery_attempt_count',
        'transport_deadline_at',
        'delivery_watchdog_at',
        'active_attempt_token_hash',
        'claimed_at',
        'attempt_lease_expires_at',
    ];

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::SCHEMA) {
            throw new InvalidArgumentException('invalid_schema');
        }

        CanonicalSupplierContract::enum($values['authorization_action'], 'authorization_action', self::ACTIONS);
        CanonicalSupplierContract::positiveInteger($values['execution_claim_id'], 'execution_claim_id');
        CanonicalSupplierContract::positiveInteger($values['dispatch_outbox_id'], 'dispatch_outbox_id');
        CanonicalSupplierContract::hex64($values['logical_execution_key'], 'logical_execution_key');
        CanonicalSupplierContract::enum($values['execution_path'], 'execution_path', ['orchestrated', 'legacy_xml']);
        CanonicalSupplierContract::enum($values['claim_state'], 'claim_state', self::CLAIM_STATES);
        CanonicalSupplierContract::enum($values['outbox_state'], 'outbox_state', self::OUTBOX_STATES);
        CanonicalSupplierContract::positiveInteger($values['supplier_id'], 'supplier_id');
        CanonicalSupplierContract::nullablePositiveInteger($values['supplier_import_run_id'], 'supplier_import_run_id');
        CanonicalSupplierContract::nullablePositiveInteger($values['supplier_feed_id'], 'supplier_feed_id');
        CanonicalSupplierContract::nullablePositiveInteger($values['import_job_id'], 'import_job_id');
        CanonicalSupplierContract::nullablePositiveInteger($values['import_history_id'], 'import_history_id');
        CanonicalSupplierContract::unsignedInteger($values['publication_attempt_count'], 'publication_attempt_count', 8);
        CanonicalSupplierContract::unsignedInteger($values['delivery_attempt_count'], 'delivery_attempt_count', 8);
        CanonicalSupplierContract::mysqlUtcMicroseconds($values['transport_deadline_at'], 'transport_deadline_at');
        CanonicalSupplierContract::nullableMysqlUtcMicroseconds($values['delivery_watchdog_at'], 'delivery_watchdog_at');
        CanonicalSupplierContract::nullableHex64($values['active_attempt_token_hash'], 'active_attempt_token_hash');
        CanonicalSupplierContract::nullableMysqlUtcMicroseconds($values['claimed_at'], 'claimed_at');
        CanonicalSupplierContract::nullableMysqlUtcMicroseconds($values['attempt_lease_expires_at'], 'attempt_lease_expires_at');

        self::assertCrossFieldState($values);

        return new self($values);
    }

    /** @param array<string, mixed> $values */
    private static function assertCrossFieldState(array $values): void
    {
        if (($values['execution_path'] === 'orchestrated') !== ($values['supplier_import_run_id'] !== null)) {
            throw new InvalidArgumentException('invalid_execution_path_parent');
        }

        if (($values['supplier_feed_id'] === null) !== ($values['import_job_id'] === null)) {
            throw new InvalidArgumentException('invalid_allocation_tuple');
        }

        if ($values['execution_path'] === 'legacy_xml' && $values['supplier_feed_id'] === null) {
            throw new InvalidArgumentException('invalid_legacy_parent_tuple');
        }

        $ownerNulls = [
            $values['active_attempt_token_hash'] === null,
            $values['claimed_at'] === null,
            $values['attempt_lease_expires_at'] === null,
        ];

        if (count(array_unique($ownerNulls, SORT_REGULAR)) !== 1) {
            throw new InvalidArgumentException('invalid_attempt_owner_tuple');
        }

        if ($values['delivery_watchdog_at'] !== null && $values['outbox_state'] !== 'published') {
            throw new InvalidArgumentException('invalid_delivery_watchdog_state');
        }
    }
}
