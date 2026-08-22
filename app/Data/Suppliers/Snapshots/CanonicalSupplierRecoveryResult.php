<?php

namespace App\Data\Suppliers\Snapshots;

use InvalidArgumentException;

final readonly class CanonicalSupplierRecoveryResult extends FixedOrderCanonicalJson
{
    public const SCHEMA = 'supplier-import-dispatch-recovery-result-v1';

    protected const DOMAIN = 'supplier-import-dispatch-recovery-result-v1';

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
        'event_sequence',
        'event_kind',
        'expected_state_fingerprint',
        'resume_state_fingerprint',
        'canonical_result_code',
        'occurred_at',
    ];

    private const TERMINALIZATION_CODES = [
        'terminalize_stale_dispatch' => [
            'transport_delivery_budget_exhausted',
            'transport_deadline_expired',
            'dispatch_watchdog_operator_terminalized',
            'dispatch_watchdog_response_expired',
            'dispatch_publication_attempts_exhausted',
        ],
        'terminalize_publication_mismatch' => ['dispatch_publication_mismatch'],
        'terminalize_abandoned_processing' => ['processing_lease_abandoned'],
    ];

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::SCHEMA) {
            throw new InvalidArgumentException('invalid_schema');
        }

        CanonicalSupplierContract::positiveInteger($values['authorization_id'], 'authorization_id');
        CanonicalSupplierContract::enum(
            $values['authorization_action'],
            'authorization_action',
            CanonicalSupplierRecoveryExpectedStateV2::ACTIONS,
        );
        CanonicalSupplierContract::positiveInteger($values['authorized_operator_id'], 'authorized_operator_id');
        CanonicalSupplierContract::positiveInteger($values['execution_claim_id'], 'execution_claim_id');
        CanonicalSupplierContract::positiveInteger($values['dispatch_outbox_id'], 'dispatch_outbox_id');
        CanonicalSupplierContract::hex64($values['logical_execution_key'], 'logical_execution_key');
        CanonicalSupplierContract::enum($values['target_parent_type'], 'target_parent_type', [
            'supplier_import_run',
            'supplier_feed',
        ]);
        CanonicalSupplierContract::positiveInteger($values['target_parent_id'], 'target_parent_id');
        CanonicalSupplierContract::unsignedInteger($values['event_sequence'], 'event_sequence', 2);
        CanonicalSupplierContract::enum($values['event_kind'], 'event_kind', [
            'started',
            'republish_succeeded',
            'terminalization_succeeded',
            'ownership_recovery_succeeded',
            'publish_failed',
            'action_stopped',
            'rejected',
            'already_terminal',
        ]);
        CanonicalSupplierContract::hex64($values['expected_state_fingerprint'], 'expected_state_fingerprint');
        CanonicalSupplierContract::nullableHex64($values['resume_state_fingerprint'], 'resume_state_fingerprint');
        CanonicalSupplierContract::asciiString($values['canonical_result_code'], 'canonical_result_code', 96);
        CanonicalSupplierContract::mysqlUtcMicroseconds($values['occurred_at'], 'occurred_at');

        self::assertEventContract($values);

        return new self($values);
    }

    /** @param array<string, mixed> $values */
    private static function assertEventContract(array $values): void
    {
        $event = $values['event_kind'];
        $action = $values['authorization_action'];
        $code = $values['canonical_result_code'];
        $sequence = $values['event_sequence'];
        $resume = $values['resume_state_fingerprint'];

        $sequenceValid = in_array($event, ['started', 'rejected', 'already_terminal'], true)
            ? $sequence === 1
            : $sequence === 2;
        $resumeValid = $event === 'started' && $action === 'republish_same_key'
            ? $resume !== null
            : $resume === null;

        if (! $sequenceValid || ! $resumeValid || ! self::isAllowedEventCode($action, $event, $code)) {
            throw new InvalidArgumentException('invalid_recovery_result_event');
        }
    }

    private static function isAllowedEventCode(string $action, string $event, string $code): bool
    {
        if ($event === 'started') {
            return $code === 'authorization_attempt_started';
        }

        if ($event === 'rejected') {
            return in_array($code, [
                'authorization_expired',
                'state_fingerprint_mismatch',
                'resume_state_fingerprint_mismatch',
                'state_conflict',
                'noncanonical_parent',
                'action_not_permitted',
                'response_window_expired',
                'monitor_integrity_not_healthy',
            ], true);
        }

        if ($event === 'already_terminal') {
            return $code === 'already_terminal_noop';
        }

        if ($action === 'republish_same_key') {
            return match ($event) {
                'republish_succeeded' => $code === 'dispatch_republished_same_key',
                'publish_failed' => in_array($code, [
                    'dispatch_publication_failed',
                    'dispatch_publication_attempts_exhausted',
                ], true),
                'action_stopped' => in_array($code, [
                    'republish_delivery_budget_exhausted_after_start',
                    'republish_transport_deadline_expired_after_start',
                    'republish_response_window_expired_after_start',
                    'monitor_integrity_not_healthy_after_start',
                    'republish_state_conflict_after_start',
                ], true),
                default => false,
            };
        }

        if ($action === 'recover_expired_queued_ownership') {
            return $event === 'ownership_recovery_succeeded' && $code === 'queued_ownership_lease_expired';
        }

        return $event === 'terminalization_succeeded'
            && in_array($code, self::TERMINALIZATION_CODES[$action] ?? [], true);
    }
}
