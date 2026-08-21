<?php

use Database\Migrations\Support\CanonicalSupplierSnapshotSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support/CanonicalSupplierSnapshotSchema.php';

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_import_execution_claims', function (Blueprint $table): void {
            $table->id();
            CanonicalSupplierSnapshotSchema::ascii($table->char('logical_execution_key', 64));
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('supplier_feed_id')->nullable();
            $table->unsignedBigInteger('supplier_import_run_id')->nullable();
            $table->unsignedBigInteger('import_job_id')->nullable();
            $table->timestamp('allocated_at', 6)->nullable();
            $table->unsignedBigInteger('import_history_id')->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('execution_path', 32));
            CanonicalSupplierSnapshotSchema::ascii($table->string('state', 32))->default('pending_dispatch');
            CanonicalSupplierSnapshotSchema::ascii($table->char('active_attempt_token_hash', 64))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('source_fingerprint', 64))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('cohort_authorization_version', 32))->nullable();
            $table->timestamp('cohort_authorized_at', 6)->nullable();
            $table->unsignedBigInteger('cohort_seed_count')->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('cohort_seed_fingerprint', 64))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('terminal_reason_code', 96))->nullable();
            $table->timestamp('claimed_at', 6)->nullable();
            $table->timestamp('attempt_lease_expires_at', 6)->nullable();
            $table->timestamp('processing_started_at', 6)->nullable();
            $table->timestamp('terminal_at', 6)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

            $table->unique('logical_execution_key', 'uq_import_execution_claim_logical_key');
            $table->unique(['id', 'logical_execution_key'], 'uq_import_execution_claim_id_key');
            $table->index('supplier_id', 'ix_import_execution_claim_supplier');
            $table->index('supplier_feed_id', 'ix_import_execution_claim_feed');
            $table->unique('supplier_import_run_id', 'uq_import_execution_claim_run');
            $table->unique('import_job_id', 'uq_import_execution_claim_job');
            $table->index(
                ['import_job_id', 'supplier_id', 'supplier_feed_id'],
                'ix_import_execution_claim_job_owner_fk',
            );
            $table->unique('import_history_id', 'uq_import_execution_claim_history');
            $table->index(
                ['supplier_id', 'supplier_feed_id', 'state', 'id'],
                'ix_import_execution_claim_scope_state',
            );

            $table->foreign('supplier_id', 'fk_import_execution_claim_supplier')
                ->references('id')->on('suppliers')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign('supplier_feed_id', 'fk_import_execution_claim_feed')
                ->references('id')->on('supplier_feeds')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign('supplier_import_run_id', 'fk_import_execution_claim_run')
                ->references('id')->on('supplier_import_runs')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(
                ['import_job_id', 'supplier_id', 'supplier_feed_id'],
                'fk_import_execution_claim_job_scope',
            )->references(['id', 'supplier_id', 'supplier_feed_id'])
                ->on('import_jobs')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign('import_history_id', 'fk_import_execution_claim_history')
                ->references('id')->on('import_histories')->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_import_execution_claims', [
            'chk_import_execution_claim_logical_key' => self::requiredHex('logical_execution_key'),
            'chk_import_execution_claim_source_fingerprint' => self::nullableHex('source_fingerprint'),
            'chk_import_execution_claim_allocation_pair' => <<<'SQL'
                (supplier_feed_id IS NULL AND import_job_id IS NULL AND allocated_at IS NULL)
                OR
                (supplier_feed_id IS NOT NULL AND import_job_id IS NOT NULL AND allocated_at IS NOT NULL)
                SQL,
            'chk_import_execution_claim_history_allocation' => <<<'SQL'
                import_history_id IS NULL
                OR (supplier_feed_id IS NOT NULL AND import_job_id IS NOT NULL AND allocated_at IS NOT NULL)
                SQL,
            'chk_import_execution_claim_fingerprint_allocation' => <<<'SQL'
                source_fingerprint IS NULL
                OR (supplier_feed_id IS NOT NULL AND import_job_id IS NOT NULL AND allocated_at IS NOT NULL)
                SQL,
            'chk_import_execution_claim_processing_allocation' => <<<'SQL'
                state <> 'processing'
                OR (
                    supplier_feed_id IS NOT NULL
                    AND import_job_id IS NOT NULL
                    AND allocated_at IS NOT NULL
                    AND import_history_id IS NOT NULL
                    AND source_fingerprint IS NOT NULL
                    AND processing_started_at IS NOT NULL
                )
                SQL,
            'chk_import_execution_claim_terminal_evidence_allocation' => <<<'SQL'
                state NOT IN ('terminal_qualified', 'terminal_frozen')
                OR (
                    supplier_feed_id IS NOT NULL
                    AND import_job_id IS NOT NULL
                    AND allocated_at IS NOT NULL
                    AND import_history_id IS NOT NULL
                )
                SQL,
            'chk_import_execution_claim_path_parent' => <<<'SQL'
                (
                    BINARY execution_path = BINARY _ascii'orchestrated'
                    AND OCTET_LENGTH(execution_path) = 12
                    AND supplier_import_run_id IS NOT NULL
                )
                OR
                (
                    BINARY execution_path = BINARY _ascii'legacy_xml'
                    AND OCTET_LENGTH(execution_path) = 10
                    AND supplier_import_run_id IS NULL
                    AND supplier_feed_id IS NOT NULL
                    AND import_job_id IS NOT NULL
                )
                SQL,
            'chk_import_claim_state' => <<<'SQL'
                state IN (
                    'pending_dispatch', 'queued', 'processing',
                    'terminal_qualified', 'terminal_frozen', 'terminal_failed'
                )
                SQL,
            'chk_import_claim_attempt_tuple' => <<<'SQL'
                (
                    active_attempt_token_hash IS NULL
                    AND claimed_at IS NULL
                    AND attempt_lease_expires_at IS NULL
                )
                OR
                (
                    active_attempt_token_hash IS NOT NULL
                    AND claimed_at IS NOT NULL
                    AND attempt_lease_expires_at IS NOT NULL
                )
                SQL,
            'chk_import_claim_attempt_hash' => self::nullableHex('active_attempt_token_hash'),
            'chk_import_claim_cohort_authorization_tuple' => <<<'SQL'
                (
                    cohort_authorization_version IS NULL
                    AND cohort_authorized_at IS NULL
                    AND cohort_seed_count IS NULL
                    AND cohort_seed_fingerprint IS NULL
                )
                OR
                (
                    cohort_authorization_version IS NOT NULL
                    AND cohort_authorized_at IS NOT NULL
                    AND cohort_seed_count IS NOT NULL
                    AND cohort_seed_fingerprint IS NOT NULL
                )
                SQL,
            'chk_import_claim_cohort_seed_hash' => self::nullableHex('cohort_seed_fingerprint'),
            'chk_import_claim_cohort_auth_version' => <<<'SQL'
                cohort_authorization_version IS NULL
                OR cohort_authorization_version = 'supplier_offer_cohort_v1'
                SQL,
            'chk_import_claim_cohort_auth_time' => <<<'SQL'
                cohort_authorized_at IS NULL
                OR (allocated_at IS NOT NULL AND cohort_authorized_at >= allocated_at)
                SQL,
            'chk_import_claim_processing_owner' => <<<'SQL'
                state <> 'processing'
                OR (
                    supplier_feed_id IS NOT NULL
                    AND import_job_id IS NOT NULL
                    AND allocated_at IS NOT NULL
                    AND import_history_id IS NOT NULL
                    AND source_fingerprint IS NOT NULL
                    AND cohort_authorization_version IS NOT NULL
                    AND cohort_authorized_at IS NOT NULL
                    AND cohort_seed_count IS NOT NULL
                    AND cohort_seed_fingerprint IS NOT NULL
                    AND processing_started_at IS NOT NULL
                    AND active_attempt_token_hash IS NOT NULL
                    AND claimed_at IS NOT NULL
                    AND attempt_lease_expires_at IS NOT NULL
                )
                SQL,
            'chk_import_claim_terminal_owner_clear' => <<<'SQL'
                state NOT IN ('terminal_qualified', 'terminal_frozen', 'terminal_failed')
                OR (
                    active_attempt_token_hash IS NULL
                    AND claimed_at IS NULL
                    AND attempt_lease_expires_at IS NULL
                )
                SQL,
            'chk_import_claim_terminal_fields' => <<<'SQL'
                (
                    state = 'terminal_qualified'
                    AND terminal_at IS NOT NULL
                    AND terminal_reason_code IS NULL
                )
                OR (
                    state IN ('terminal_frozen', 'terminal_failed')
                    AND terminal_at IS NOT NULL
                    AND terminal_reason_code IS NOT NULL
                )
                OR (
                    state NOT IN ('terminal_qualified', 'terminal_frozen', 'terminal_failed')
                    AND terminal_at IS NULL
                    AND terminal_reason_code IS NULL
                )
                SQL,
            'chk_import_claim_attempt_time_order' => 'claimed_at IS NULL OR claimed_at < attempt_lease_expires_at',
            'chk_import_claim_processing_marker' => <<<'SQL'
                processing_started_at IS NULL
                OR state IN (
                    'processing', 'terminal_qualified', 'terminal_frozen', 'terminal_failed'
                )
                SQL,
            'chk_import_claim_processing_time_order' => <<<'SQL'
                processing_started_at IS NULL
                OR state IN ('terminal_qualified', 'terminal_frozen', 'terminal_failed')
                OR (
                    claimed_at IS NOT NULL
                    AND processing_started_at >= claimed_at
                    AND processing_started_at < attempt_lease_expires_at
                )
                SQL,
        ]);

        if (CanonicalSupplierSnapshotSchema::isMySql()) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER `trg_import_execution_claim_path_immutable`
                BEFORE UPDATE ON `supplier_import_execution_claims`
                FOR EACH ROW
                BEGIN
                    IF BINARY OLD.execution_path <> BINARY NEW.execution_path THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Execution claim path is immutable';
                    END IF;
                END
                SQL);
        }
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
            '2026_08_20_120001_create_supplier_import_execution_claims_table',
            function (): void {
                CanonicalSupplierSnapshotSchema::dropTriggers([
                    'trg_import_execution_claim_path_immutable',
                ]);
                Schema::drop('supplier_import_execution_claims');
            },
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
