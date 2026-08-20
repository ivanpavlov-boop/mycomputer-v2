<?php

use Database\Migrations\Support\CanonicalSupplierSnapshotSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support/CanonicalSupplierSnapshotSchema.php';

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_import_dispatch_recovery_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_import_dispatch_recovery_authorization_id');
            CanonicalSupplierSnapshotSchema::ascii($table->string('authorization_action', 64));
            $table->unsignedBigInteger('authorized_operator_id');
            $table->unsignedBigInteger('supplier_import_execution_claim_id');
            $table->unsignedBigInteger('supplier_import_dispatch_outbox_id');
            CanonicalSupplierSnapshotSchema::ascii($table->char('logical_execution_key', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->string('target_parent_type', 32));
            $table->unsignedBigInteger('target_parent_id');
            $table->unsignedSmallInteger('event_sequence');
            CanonicalSupplierSnapshotSchema::ascii($table->string('event_kind', 48));
            CanonicalSupplierSnapshotSchema::ascii($table->string('canonical_result_code', 96));
            CanonicalSupplierSnapshotSchema::ascii($table->char('resume_state_fingerprint', 64))->nullable();
            $table->timestamp('occurred_at', 6);
            CanonicalSupplierSnapshotSchema::ascii($table->char('result_fingerprint', 64));

            if (CanonicalSupplierSnapshotSchema::isMySql()) {
                $table->unsignedTinyInteger('started_once_guard')->nullable()->storedAs(<<<'SQL'
                    CASE
                        WHEN BINARY event_kind = BINARY _ascii'started' THEN 1
                        ELSE NULL
                    END
                    SQL);
                $table->unsignedTinyInteger('terminal_once_guard')->nullable()->storedAs(<<<'SQL'
                    CASE
                        WHEN BINARY event_kind IN (
                            BINARY _ascii'republish_succeeded',
                            BINARY _ascii'terminalization_succeeded',
                            BINARY _ascii'ownership_recovery_succeeded',
                            BINARY _ascii'publish_failed',
                            BINARY _ascii'action_stopped',
                            BINARY _ascii'rejected',
                            BINARY _ascii'already_terminal'
                        ) THEN 1
                        ELSE NULL
                    END
                    SQL);
            } else {
                $table->unsignedTinyInteger('started_once_guard')->nullable();
                $table->unsignedTinyInteger('terminal_once_guard')->nullable();
            }

            $table->unique(
                ['supplier_import_dispatch_recovery_authorization_id', 'event_sequence'],
                'uq_import_recovery_result_auth_sequence',
            );
            $table->unique(
                ['supplier_import_dispatch_recovery_authorization_id', 'started_once_guard'],
                'uq_import_recovery_result_auth_started',
            );
            $table->unique(
                ['supplier_import_dispatch_recovery_authorization_id', 'terminal_once_guard'],
                'uq_import_recovery_result_auth_terminal',
            );
            $table->index([
                'supplier_import_dispatch_recovery_authorization_id',
                'authorization_action',
                'authorized_operator_id',
                'supplier_import_execution_claim_id',
                'supplier_import_dispatch_outbox_id',
                'logical_execution_key',
                'target_parent_type',
                'target_parent_id',
            ], 'ix_import_recovery_result_complete_auth_tuple');
            $table->index(
                ['supplier_import_execution_claim_id', 'occurred_at', 'id'],
                'ix_import_recovery_result_claim',
            );
            $table->index(
                ['supplier_import_dispatch_outbox_id', 'supplier_import_execution_claim_id'],
                'ix_import_recovery_result_outbox_claim',
            );
            $table->index(
                ['authorized_operator_id', 'occurred_at', 'id'],
                'ix_import_recovery_result_operator',
            );

            $table->foreign(
                'supplier_import_dispatch_recovery_authorization_id',
                'fk_import_recovery_result_auth',
            )->references('id')->on('supplier_import_dispatch_recovery_authorizations')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->foreign([
                'supplier_import_dispatch_recovery_authorization_id',
                'authorization_action',
                'authorized_operator_id',
                'supplier_import_execution_claim_id',
                'supplier_import_dispatch_outbox_id',
                'logical_execution_key',
                'target_parent_type',
                'target_parent_id',
            ], 'fk_import_recovery_result_complete_auth_tuple')
                ->references([
                    'id',
                    'authorization_action',
                    'authorized_operator_id',
                    'supplier_import_execution_claim_id',
                    'supplier_import_dispatch_outbox_id',
                    'logical_execution_key',
                    'target_parent_type',
                    'target_parent_id',
                ])->on('supplier_import_dispatch_recovery_authorizations')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_import_dispatch_recovery_results', [
            'chk_import_recovery_result_event' => <<<'SQL'
                BINARY event_kind IN (
                    BINARY _ascii'started',
                    BINARY _ascii'republish_succeeded',
                    BINARY _ascii'terminalization_succeeded',
                    BINARY _ascii'ownership_recovery_succeeded',
                    BINARY _ascii'publish_failed',
                    BINARY _ascii'action_stopped',
                    BINARY _ascii'rejected',
                    BINARY _ascii'already_terminal'
                )
                SQL,
            'chk_import_recovery_result_sequence' => <<<'SQL'
                (BINARY event_kind IN (
                    BINARY _ascii'started',
                    BINARY _ascii'rejected',
                    BINARY _ascii'already_terminal'
                ) AND event_sequence = 1)
                OR
                (BINARY event_kind IN (
                    BINARY _ascii'republish_succeeded',
                    BINARY _ascii'terminalization_succeeded',
                    BINARY _ascii'ownership_recovery_succeeded',
                    BINARY _ascii'publish_failed',
                    BINARY _ascii'action_stopped'
                ) AND event_sequence = 2)
                SQL,
            'chk_import_recovery_result_fingerprint' => self::requiredHex('result_fingerprint'),
            'chk_import_recovery_result_resume_fingerprint' => <<<'SQL'
                (
                    BINARY event_kind = BINARY _ascii'started'
                    AND BINARY authorization_action = BINARY _ascii'republish_same_key'
                    AND OCTET_LENGTH(resume_state_fingerprint) = 64
                    AND REGEXP_LIKE(resume_state_fingerprint, _ascii'^[0-9a-f]{64}$', 'c')
                ) OR (
                    NOT (
                        BINARY event_kind = BINARY _ascii'started'
                        AND BINARY authorization_action = BINARY _ascii'republish_same_key'
                    )
                    AND resume_state_fingerprint IS NULL
                )
                SQL,
            'chk_import_recovery_result_action_event_code' => <<<'SQL'
                (BINARY event_kind = BINARY _ascii'started'
                    AND BINARY canonical_result_code = BINARY _ascii'authorization_attempt_started')
                OR
                (BINARY event_kind = BINARY _ascii'rejected'
                    AND BINARY canonical_result_code IN (
                        BINARY _ascii'authorization_expired',
                        BINARY _ascii'state_fingerprint_mismatch',
                        BINARY _ascii'resume_state_fingerprint_mismatch',
                        BINARY _ascii'state_conflict',
                        BINARY _ascii'noncanonical_parent',
                        BINARY _ascii'action_not_permitted',
                        BINARY _ascii'response_window_expired',
                        BINARY _ascii'monitor_integrity_not_healthy'
                    ))
                OR
                (BINARY event_kind = BINARY _ascii'already_terminal'
                    AND BINARY canonical_result_code = BINARY _ascii'already_terminal_noop')
                OR
                (BINARY authorization_action = BINARY _ascii'republish_same_key'
                    AND (
                        (BINARY event_kind = BINARY _ascii'republish_succeeded'
                            AND BINARY canonical_result_code = BINARY _ascii'dispatch_republished_same_key')
                        OR
                        (BINARY event_kind = BINARY _ascii'publish_failed'
                            AND BINARY canonical_result_code IN (
                                BINARY _ascii'dispatch_publication_failed',
                                BINARY _ascii'dispatch_publication_attempts_exhausted'
                            ))
                        OR
                        (BINARY event_kind = BINARY _ascii'action_stopped'
                            AND BINARY canonical_result_code IN (
                                BINARY _ascii'republish_delivery_budget_exhausted_after_start',
                                BINARY _ascii'republish_transport_deadline_expired_after_start',
                                BINARY _ascii'republish_response_window_expired_after_start',
                                BINARY _ascii'monitor_integrity_not_healthy_after_start',
                                BINARY _ascii'republish_state_conflict_after_start'
                            ))
                    ))
                OR
                (BINARY authorization_action = BINARY _ascii'recover_expired_queued_ownership'
                    AND BINARY event_kind = BINARY _ascii'ownership_recovery_succeeded'
                    AND BINARY canonical_result_code = BINARY _ascii'queued_ownership_lease_expired')
                OR
                (BINARY authorization_action = BINARY _ascii'terminalize_stale_dispatch'
                    AND BINARY event_kind = BINARY _ascii'terminalization_succeeded'
                    AND BINARY canonical_result_code IN (
                        BINARY _ascii'transport_delivery_budget_exhausted',
                        BINARY _ascii'transport_deadline_expired',
                        BINARY _ascii'dispatch_watchdog_operator_terminalized',
                        BINARY _ascii'dispatch_watchdog_response_expired',
                        BINARY _ascii'dispatch_publication_attempts_exhausted'
                    ))
                OR
                (BINARY authorization_action = BINARY _ascii'terminalize_publication_mismatch'
                    AND BINARY event_kind = BINARY _ascii'terminalization_succeeded'
                    AND BINARY canonical_result_code = BINARY _ascii'dispatch_publication_mismatch')
                OR
                (BINARY authorization_action = BINARY _ascii'terminalize_abandoned_processing'
                    AND BINARY event_kind = BINARY _ascii'terminalization_succeeded'
                    AND BINARY canonical_result_code = BINARY _ascii'processing_lease_abandoned')
                SQL,
        ]);

        CanonicalSupplierSnapshotSchema::addNoMutationTriggers(
            'supplier_import_dispatch_recovery_results',
            'trg_import_recovery_result_no_update',
            'trg_import_recovery_result_no_delete',
        );
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::assertDestructiveDownAllowed();
        CanonicalSupplierSnapshotSchema::dropTriggers([
            'trg_import_recovery_result_no_update',
            'trg_import_recovery_result_no_delete',
        ]);
        Schema::drop('supplier_import_dispatch_recovery_results');
    }

    private static function requiredHex(string $column): string
    {
        return sprintf(
            "OCTET_LENGTH(%s) = 64 AND REGEXP_LIKE(%s, _ascii'^[0-9a-f]{64}$', 'c')",
            $column,
            $column,
        );
    }
};
