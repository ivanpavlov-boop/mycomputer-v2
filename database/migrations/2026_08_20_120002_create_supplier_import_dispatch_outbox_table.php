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
        Schema::create('supplier_import_dispatch_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_import_execution_claim_id');
            CanonicalSupplierSnapshotSchema::ascii($table->char('logical_execution_key', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->string('event_type', 48))->default('initial_dispatch');
            CanonicalSupplierSnapshotSchema::ascii($table->string('job_type', 48));
            $table->json('dispatch_payload');
            CanonicalSupplierSnapshotSchema::ascii($table->char('dispatch_payload_hash', 64));
            $table->timestamp('transport_deadline_at', 6);
            CanonicalSupplierSnapshotSchema::ascii($table->string('state', 32))->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('publication_attempt_generation')->default(0);
            CanonicalSupplierSnapshotSchema::ascii($table->string('publication_attempt_state', 32))->default('none');
            CanonicalSupplierSnapshotSchema::ascii($table->char('publication_attempt_token_hash', 64))->nullable();
            $table->timestamp('publication_attempt_reserved_at', 6)->nullable();
            $table->timestamp('publication_attempt_lease_expires_at', 6)->nullable();
            $table->timestamp('publication_external_fence_installed_at', 6)->nullable();
            $table->timestamp('publication_call_boundary_at', 6)->nullable();
            $table->timestamp('publication_attempt_resolved_at', 6)->nullable();
            $table->unsignedSmallInteger('delivery_attempt_count')->default(0);
            CanonicalSupplierSnapshotSchema::ascii($table->string('lease_owner_key', 96))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('lease_token_hash', 64))->nullable();
            $table->timestamp('leased_at', 6)->nullable();
            $table->timestamp('lease_expires_at', 6)->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('published_at', 6)->nullable();
            $table->timestamp('last_published_at', 6)->nullable();
            $table->timestamp('delivery_watchdog_at', 6)->nullable();
            $table->timestamp('recovery_required_at', 6)->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('recovery_reason_code', 96))->nullable();
            $table->timestamp('terminal_at', 6)->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('terminal_failure_reason_code', 96))->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

            $table->unique(
                ['supplier_import_execution_claim_id', 'event_type'],
                'uq_import_dispatch_outbox_claim_event',
            );
            $table->unique(
                ['supplier_import_execution_claim_id', 'logical_execution_key'],
                'uq_import_dispatch_outbox_claim_key',
            );
            $table->unique(
                ['id', 'supplier_import_execution_claim_id'],
                'uq_import_dispatch_outbox_id_claim',
            );
            $table->index(['state', 'next_attempt_at', 'id'], 'ix_import_dispatch_outbox_due');
            $table->index(['state', 'lease_expires_at', 'id'], 'ix_import_dispatch_outbox_lease');
            $table->index(
                ['state', 'delivery_watchdog_at', 'id'],
                'ix_import_dispatch_outbox_state_watchdog_id',
            );

            $table->foreign(
                'supplier_import_execution_claim_id',
                'fk_import_dispatch_outbox_claim',
            )->references('id')->on('supplier_import_execution_claims')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(
                ['supplier_import_execution_claim_id', 'logical_execution_key'],
                'fk_import_dispatch_outbox_claim_key',
            )->references(['id', 'logical_execution_key'])
                ->on('supplier_import_execution_claims')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_import_dispatch_outbox', [
            'chk_import_outbox_logical_key' => self::requiredHex('logical_execution_key'),
            'chk_import_outbox_payload_hash' => self::requiredHex('dispatch_payload_hash'),
            'chk_import_outbox_lease_hash' => self::nullableHex('lease_token_hash'),
            'chk_import_outbox_publication_token_hash' => self::nullableHex('publication_attempt_token_hash'),
            'chk_import_outbox_event_type' => "BINARY event_type = BINARY _ascii'initial_dispatch'",
            'chk_import_outbox_job_type' => <<<'SQL'
                BINARY job_type IN (
                    BINARY _ascii'run_supplier_import',
                    BINARY _ascii'process_xml_supplier_feed'
                )
                SQL,
            'chk_import_outbox_payload_object' => "JSON_TYPE(dispatch_payload) = 'OBJECT'",
            'chk_import_outbox_state' => "state IN ('pending', 'leased', 'published', 'recovery_required', 'terminal_failed')",
            'chk_import_outbox_attempt_bound' => 'attempt_count >= 0 AND attempt_count <= 8',
            'chk_import_outbox_publication_attempt_state' => <<<'SQL'
                BINARY publication_attempt_state IN (
                    BINARY _ascii'none',
                    BINARY _ascii'reserved',
                    BINARY _ascii'external_fence_installed',
                    BINARY _ascii'call_boundary_entered',
                    BINARY _ascii'durable_success',
                    BINARY _ascii'durable_failure',
                    BINARY _ascii'outcome_unknown'
                )
                SQL,
            'chk_import_outbox_publication_attempt_tuple' => <<<'SQL'
                (
                    publication_attempt_state = 'none'
                    AND publication_attempt_generation = 0
                    AND attempt_count = 0
                    AND publication_attempt_token_hash IS NULL
                    AND publication_attempt_reserved_at IS NULL
                    AND publication_attempt_lease_expires_at IS NULL
                    AND publication_external_fence_installed_at IS NULL
                    AND publication_call_boundary_at IS NULL
                    AND publication_attempt_resolved_at IS NULL
                )
                OR (
                    publication_attempt_state = 'reserved'
                    AND publication_attempt_generation > 0
                    AND attempt_count > 0
                    AND publication_attempt_token_hash IS NOT NULL
                    AND publication_attempt_reserved_at IS NOT NULL
                    AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
                    AND publication_external_fence_installed_at IS NULL
                    AND publication_call_boundary_at IS NULL
                    AND publication_attempt_resolved_at IS NULL
                )
                OR (
                    publication_attempt_state = 'external_fence_installed'
                    AND publication_attempt_generation > 0
                    AND attempt_count > 0
                    AND publication_attempt_token_hash IS NOT NULL
                    AND publication_attempt_reserved_at IS NOT NULL
                    AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
                    AND publication_external_fence_installed_at >= publication_attempt_reserved_at
                    AND publication_call_boundary_at IS NULL
                    AND publication_attempt_resolved_at IS NULL
                )
                OR (
                    publication_attempt_state = 'call_boundary_entered'
                    AND publication_attempt_generation > 0
                    AND attempt_count > 0
                    AND publication_attempt_token_hash IS NOT NULL
                    AND publication_attempt_reserved_at IS NOT NULL
                    AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
                    AND publication_external_fence_installed_at >= publication_attempt_reserved_at
                    AND publication_call_boundary_at >= publication_external_fence_installed_at
                    AND publication_attempt_resolved_at IS NULL
                )
                OR (
                    publication_attempt_state IN ('durable_success', 'durable_failure')
                    AND publication_attempt_generation > 0
                    AND attempt_count > 0
                    AND publication_attempt_token_hash IS NULL
                    AND publication_attempt_reserved_at IS NOT NULL
                    AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
                    AND publication_external_fence_installed_at >= publication_attempt_reserved_at
                    AND publication_call_boundary_at >= publication_external_fence_installed_at
                    AND publication_attempt_resolved_at >= publication_call_boundary_at
                )
                OR (
                    publication_attempt_state = 'outcome_unknown'
                    AND publication_attempt_generation > 0
                    AND attempt_count > 0
                    AND publication_attempt_token_hash IS NULL
                    AND publication_attempt_reserved_at IS NOT NULL
                    AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
                    AND (
                        publication_external_fence_installed_at IS NULL
                        OR publication_external_fence_installed_at >= publication_attempt_reserved_at
                    )
                    AND (
                        publication_call_boundary_at IS NULL
                        OR (
                            publication_external_fence_installed_at IS NOT NULL
                            AND publication_call_boundary_at >= publication_external_fence_installed_at
                        )
                    )
                    AND publication_attempt_resolved_at >= publication_attempt_reserved_at
                )
                SQL,
            'chk_import_outbox_delivery_attempt_bound' => 'delivery_attempt_count >= 0 AND delivery_attempt_count <= 8',
            'chk_import_outbox_transport_deadline' => 'transport_deadline_at = TIMESTAMPADD(HOUR, 24, created_at)',
            'chk_import_outbox_lease_tuple' => <<<'SQL'
                (
                    lease_owner_key IS NULL
                    AND lease_token_hash IS NULL
                    AND leased_at IS NULL
                    AND lease_expires_at IS NULL
                )
                OR
                (
                    lease_owner_key IS NOT NULL
                    AND lease_token_hash IS NOT NULL
                    AND leased_at IS NOT NULL
                    AND lease_expires_at IS NOT NULL
                )
                SQL,
            'chk_import_outbox_publish_tuple' => <<<'SQL'
                (published_at IS NULL AND last_published_at IS NULL)
                OR (published_at IS NOT NULL AND last_published_at IS NOT NULL)
                SQL,
            'chk_import_outbox_state_fields' => <<<'SQL'
                (
                    state = 'pending'
                    AND lease_owner_key IS NULL
                    AND lease_token_hash IS NULL
                    AND leased_at IS NULL
                    AND lease_expires_at IS NULL
                    AND published_at IS NULL
                    AND last_published_at IS NULL
                    AND delivery_watchdog_at IS NULL
                    AND recovery_required_at IS NULL
                    AND recovery_reason_code IS NULL
                    AND terminal_at IS NULL
                    AND terminal_failure_reason_code IS NULL
                )
                OR (
                    state = 'leased'
                    AND lease_owner_key IS NOT NULL
                    AND lease_token_hash IS NOT NULL
                    AND leased_at IS NOT NULL
                    AND lease_expires_at IS NOT NULL
                    AND delivery_watchdog_at IS NULL
                    AND recovery_required_at IS NULL
                    AND recovery_reason_code IS NULL
                    AND terminal_at IS NULL
                    AND terminal_failure_reason_code IS NULL
                )
                OR (
                    state = 'published'
                    AND lease_owner_key IS NULL
                    AND lease_token_hash IS NULL
                    AND leased_at IS NULL
                    AND lease_expires_at IS NULL
                    AND published_at IS NOT NULL
                    AND last_published_at IS NOT NULL
                    AND recovery_required_at IS NULL
                    AND recovery_reason_code IS NULL
                    AND terminal_at IS NULL
                    AND terminal_failure_reason_code IS NULL
                )
                OR (
                    state = 'recovery_required'
                    AND lease_owner_key IS NULL
                    AND lease_token_hash IS NULL
                    AND leased_at IS NULL
                    AND lease_expires_at IS NULL
                    AND published_at IS NOT NULL
                    AND last_published_at IS NOT NULL
                    AND delivery_watchdog_at IS NULL
                    AND recovery_required_at IS NOT NULL
                    AND recovery_reason_code IS NOT NULL
                    AND terminal_at IS NULL
                    AND terminal_failure_reason_code IS NULL
                )
                OR (
                    state = 'terminal_failed'
                    AND lease_owner_key IS NULL
                    AND lease_token_hash IS NULL
                    AND leased_at IS NULL
                    AND lease_expires_at IS NULL
                    AND delivery_watchdog_at IS NULL
                    AND recovery_required_at IS NULL
                    AND recovery_reason_code IS NULL
                    AND terminal_at IS NOT NULL
                    AND terminal_failure_reason_code IS NOT NULL
                )
                SQL,
            'chk_import_outbox_watchdog_state' => "delivery_watchdog_at IS NULL OR state = 'published'",
            'chk_import_outbox_terminal_attempt' => <<<'SQL'
                terminal_failure_reason_code <> 'dispatch_publication_attempts_exhausted'
                OR attempt_count = 8
                SQL,
            'chk_import_outbox_timestamp_order' => <<<'SQL'
                transport_deadline_at > created_at
                AND (leased_at IS NULL OR (leased_at >= created_at AND leased_at < lease_expires_at))
                AND (next_attempt_at IS NULL OR next_attempt_at >= created_at)
                AND (published_at IS NULL OR published_at >= created_at)
                AND (last_published_at IS NULL OR last_published_at >= published_at)
                AND (delivery_watchdog_at IS NULL OR delivery_watchdog_at >= last_published_at)
                AND (recovery_required_at IS NULL OR recovery_required_at >= created_at)
                AND (terminal_at IS NULL OR terminal_at >= created_at)
                SQL,
        ]);
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
            '2026_08_20_120002_create_supplier_import_dispatch_outbox_table',
            fn () => Schema::drop('supplier_import_dispatch_outbox'),
        );
    }

    private static function requiredHex(string $column): string
    {
        return sprintf(
            "OCTET_LENGTH(%s) = 64 AND REGEXP_LIKE(%s, _ascii'^[0-9a-f]{64}$', 'c')",
            $column,
            $column,
        );
    }

    private static function nullableHex(string $column): string
    {
        return sprintf('%s IS NULL OR (%s)', $column, self::requiredHex($column));
    }
};
