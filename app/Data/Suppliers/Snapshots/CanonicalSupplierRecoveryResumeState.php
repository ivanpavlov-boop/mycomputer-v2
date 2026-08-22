<?php

namespace App\Data\Suppliers\Snapshots;

use InvalidArgumentException;

final readonly class CanonicalSupplierRecoveryResumeState extends FixedOrderCanonicalJson
{
    public const SCHEMA = 'supplier-import-dispatch-recovery-resume-v1';

    protected const DOMAIN = 'supplier-import-dispatch-recovery-resume-v1';

    protected const FIELDS = [
        'schema',
        'authorization_id',
        'authorization_action',
        'authorized_operator_id',
        'execution_claim_id',
        'dispatch_outbox_id',
        'logical_execution_key',
        'target_parent_type',
        'target_parent_id',
        'claim_state',
        'outbox_state',
        'recovery_reason_code',
        'publication_attempt_count',
        'delivery_attempt_count',
        'transport_deadline_at',
        'delivery_watchdog_at',
    ];

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::SCHEMA) {
            throw new InvalidArgumentException('invalid_schema');
        }

        CanonicalSupplierContract::positiveInteger($values['authorization_id'], 'authorization_id');
        if ($values['authorization_action'] !== 'republish_same_key') {
            throw new InvalidArgumentException('invalid_authorization_action');
        }
        CanonicalSupplierContract::positiveInteger($values['authorized_operator_id'], 'authorized_operator_id');
        CanonicalSupplierContract::positiveInteger($values['execution_claim_id'], 'execution_claim_id');
        CanonicalSupplierContract::positiveInteger($values['dispatch_outbox_id'], 'dispatch_outbox_id');
        CanonicalSupplierContract::hex64($values['logical_execution_key'], 'logical_execution_key');
        CanonicalSupplierContract::enum($values['target_parent_type'], 'target_parent_type', [
            'supplier_import_run',
            'supplier_feed',
        ]);
        CanonicalSupplierContract::positiveInteger($values['target_parent_id'], 'target_parent_id');
        CanonicalSupplierContract::enum($values['claim_state'], 'claim_state', ['pending_dispatch', 'queued']);
        CanonicalSupplierContract::enum($values['outbox_state'], 'outbox_state', ['pending', 'recovery_required']);

        if ($values['recovery_reason_code'] !== null) {
            CanonicalSupplierContract::asciiString($values['recovery_reason_code'], 'recovery_reason_code', 96);
        }

        CanonicalSupplierContract::unsignedInteger($values['publication_attempt_count'], 'publication_attempt_count', 8);
        CanonicalSupplierContract::unsignedInteger($values['delivery_attempt_count'], 'delivery_attempt_count', 8);
        CanonicalSupplierContract::mysqlUtcMicroseconds($values['transport_deadline_at'], 'transport_deadline_at');
        CanonicalSupplierContract::nullableMysqlUtcMicroseconds($values['delivery_watchdog_at'], 'delivery_watchdog_at');

        self::assertResumePair($values);

        return new self($values);
    }

    /** @param array<string, mixed> $values */
    private static function assertResumePair(array $values): void
    {
        $pending = $values['claim_state'] === 'pending_dispatch'
            && $values['outbox_state'] === 'pending'
            && $values['recovery_reason_code'] === null;
        $recovery = $values['claim_state'] === 'queued'
            && $values['outbox_state'] === 'recovery_required'
            && $values['recovery_reason_code'] !== null;

        if ((! $pending && ! $recovery) || $values['delivery_watchdog_at'] !== null) {
            throw new InvalidArgumentException('invalid_resume_state_tuple');
        }
    }
}
