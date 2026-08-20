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
        Schema::create('supplier_offer_snapshot_generations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            CanonicalSupplierSnapshotSchema::ascii($table->string('supplier_key', 96));
            $table->unsignedBigInteger('supplier_feed_id');
            $table->unsignedBigInteger('supplier_import_execution_claim_id');
            $table->unsignedBigInteger('import_history_id');
            $table->unsignedBigInteger('predecessor_snapshot_generation_id')->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('schema_version', 96));
            CanonicalSupplierSnapshotSchema::ascii($table->string('producer_version', 96));
            CanonicalSupplierSnapshotSchema::ascii($table->string('qualification_policy_key', 96));
            CanonicalSupplierSnapshotSchema::ascii($table->string('capture_integrity_policy_key', 96));
            $table->json('policy_versions');
            CanonicalSupplierSnapshotSchema::ascii($table->string('freshness_policy_key', 96))->nullable();
            $table->unsignedInteger('freshness_max_age_hours')->nullable();
            $table->boolean('freshness_policy_approved')->default(false);
            CanonicalSupplierSnapshotSchema::ascii($table->string('source_identity', 128));
            CanonicalSupplierSnapshotSchema::ascii($table->char('source_fingerprint', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->char('captured_at', 25));
            CanonicalSupplierSnapshotSchema::ascii($table->char('authoritative_snapshot_at', 25))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('capture_started_at', 25));
            CanonicalSupplierSnapshotSchema::ascii($table->char('capture_completed_at', 25));
            CanonicalSupplierSnapshotSchema::ascii($table->string('capture_outcome', 48));
            CanonicalSupplierSnapshotSchema::ascii($table->string('capture_failure_reason_code', 96))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('qualification_state', 48));
            $table->json('qualification_reason_codes');
            $table->boolean('successful')->default(false);
            $table->boolean('full')->default(false);
            $table->boolean('schema_valid')->default(false);
            $table->boolean('truncated')->default(false);
            $table->boolean('fatal_integrity_blocker')->default(false);
            $table->boolean('supplier_identity_confirmed')->default(false);
            $table->boolean('comparable')->default(false);
            $table->unsignedInteger('total_observed_count')->default(0);
            $table->unsignedInteger('valid_observation_count')->default(0);
            $table->unsignedInteger('invalid_observation_count')->default(0);
            $table->unsignedInteger('rejected_observation_count')->default(0);
            $table->unsignedInteger('duplicate_observation_count')->default(0);
            $table->unsignedInteger('enrolled_observation_count')->default(0);
            $table->unsignedInteger('minimum_product_count');
            $table->decimal('product_drop_percent', 9, 6)->nullable();
            $table->unsignedTinyInteger('maximum_product_drop_percent');
            CanonicalSupplierSnapshotSchema::ascii($table->char('cohort_fingerprint', 64))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('observation_set_fingerprint', 64))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('generation_fingerprint', 64));
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                'supplier_import_execution_claim_id',
                'uq_snapshot_generation_execution_claim',
            );
            $table->unique('import_history_id', 'uq_snapshot_generation_import_history');
            $table->index('supplier_feed_id', 'ix_snapshot_generation_feed');
            $table->index(
                ['supplier_id', 'supplier_feed_id', 'import_history_id'],
                'ix_snapshot_generation_feed_import',
            );
            $table->index(
                ['supplier_id', 'source_identity', 'import_history_id'],
                'ix_snapshot_generation_scope_order',
            );
            $table->index(
                ['supplier_id', 'source_identity', 'qualification_state', 'import_history_id'],
                'ix_snapshot_generation_qualified_range',
            );
            $table->index(['created_at', 'id'], 'ix_snapshot_generation_retention');
            $table->index(
                'predecessor_snapshot_generation_id',
                'ix_snapshot_generation_predecessor',
            );

            $table->foreign('supplier_id', 'fk_snapshot_generation_supplier')
                ->references('id')->on('suppliers')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign('supplier_feed_id', 'fk_snapshot_generation_feed')
                ->references('id')->on('supplier_feeds')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(
                'supplier_import_execution_claim_id',
                'fk_snapshot_generation_execution_claim',
            )->references('id')->on('supplier_import_execution_claims')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->foreign('import_history_id', 'fk_snapshot_generation_import_history')
                ->references('id')->on('import_histories')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(
                'predecessor_snapshot_generation_id',
                'fk_snapshot_generation_predecessor',
            )->references('id')->on('supplier_offer_snapshot_generations')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_offer_snapshot_generations', [
            'chk_snapshot_generation_source_fingerprint' => self::requiredHex('source_fingerprint'),
            'chk_snapshot_generation_cohort_fingerprint' => self::nullableHex('cohort_fingerprint'),
            'chk_snapshot_generation_observation_fingerprint' => self::nullableHex('observation_set_fingerprint'),
            'chk_snapshot_generation_fingerprint' => self::requiredHex('generation_fingerprint'),
            'chk_snapshot_generation_source_identity' => <<<'SQL'
                OCTET_LENGTH(source_identity) BETWEEN 1 AND 128
                AND REGEXP_LIKE(
                    source_identity,
                    _ascii'^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$',
                    'c'
                )
                SQL,
            'chk_snapshot_generation_capture_outcome' => <<<'SQL'
                BINARY capture_outcome IN (
                    BINARY _ascii'completed',
                    BINARY _ascii'completed_with_errors',
                    BINARY _ascii'failed',
                    BINARY _ascii'incomplete',
                    BINARY _ascii'overflow'
                )
                SQL,
            'chk_snapshot_generation_qualification_state' => <<<'SQL'
                BINARY qualification_state IN (
                    BINARY _ascii'qualified_baseline',
                    BINARY _ascii'qualified_comparable',
                    BINARY _ascii'frozen'
                )
                SQL,
            'chk_snapshot_generation_json_shapes' => <<<'SQL'
                JSON_TYPE(policy_versions) = 'OBJECT'
                AND JSON_TYPE(qualification_reason_codes) = 'ARRAY'
                SQL,
            'chk_snapshot_generation_freshness_tuple' => <<<'SQL'
                (
                    freshness_policy_key IS NULL
                    AND freshness_max_age_hours IS NULL
                    AND freshness_policy_approved = 0
                )
                OR (
                    freshness_policy_key IS NOT NULL
                    AND freshness_max_age_hours IS NOT NULL
                    AND freshness_policy_approved = 1
                )
                SQL,
            'chk_snapshot_generation_boolean_domains' => <<<'SQL'
                freshness_policy_approved IN (0, 1)
                AND successful IN (0, 1)
                AND full IN (0, 1)
                AND schema_valid IN (0, 1)
                AND truncated IN (0, 1)
                AND fatal_integrity_blocker IN (0, 1)
                AND supplier_identity_confirmed IN (0, 1)
                AND comparable IN (0, 1)
                SQL,
            'chk_snapshot_generation_timestamps' => <<<'SQL'
                REGEXP_LIKE(captured_at, _ascii'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$', 'c')
                AND REGEXP_LIKE(capture_started_at, _ascii'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$', 'c')
                AND REGEXP_LIKE(capture_completed_at, _ascii'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$', 'c')
                AND (
                    authoritative_snapshot_at IS NULL
                    OR REGEXP_LIKE(authoritative_snapshot_at, _ascii'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$', 'c')
                )
                AND captured_at = capture_completed_at
                AND capture_started_at <= capture_completed_at
                AND (authoritative_snapshot_at IS NULL OR authoritative_snapshot_at <= captured_at)
                SQL,
            'chk_snapshot_generation_count_reconciliation' => <<<'SQL'
                total_observed_count = (
                    valid_observation_count
                    + invalid_observation_count
                    + rejected_observation_count
                    + duplicate_observation_count
                )
                AND enrolled_observation_count >= valid_observation_count
                SQL,
            'chk_snapshot_generation_thresholds' => <<<'SQL'
                minimum_product_count > 0
                AND maximum_product_drop_percent <= 100
                AND (
                    product_drop_percent IS NULL
                    OR product_drop_percent BETWEEN 0 AND 100
                )
                SQL,
            'chk_snapshot_generation_qualification_tuple' => <<<'SQL'
                (
                    qualification_state = 'qualified_baseline'
                    AND predecessor_snapshot_generation_id IS NULL
                    AND comparable = 0
                    AND product_drop_percent IS NULL
                    AND JSON_LENGTH(qualification_reason_codes) = 0
                    AND successful = 1
                    AND full = 1
                    AND schema_valid = 1
                    AND truncated = 0
                    AND fatal_integrity_blocker = 0
                    AND supplier_identity_confirmed = 1
                    AND valid_observation_count >= minimum_product_count
                    AND cohort_fingerprint IS NOT NULL
                    AND observation_set_fingerprint IS NOT NULL
                )
                OR (
                    qualification_state = 'qualified_comparable'
                    AND predecessor_snapshot_generation_id IS NOT NULL
                    AND comparable = 1
                    AND product_drop_percent IS NOT NULL
                    AND product_drop_percent <= maximum_product_drop_percent
                    AND JSON_LENGTH(qualification_reason_codes) = 0
                    AND successful = 1
                    AND full = 1
                    AND schema_valid = 1
                    AND truncated = 0
                    AND fatal_integrity_blocker = 0
                    AND supplier_identity_confirmed = 1
                    AND valid_observation_count >= minimum_product_count
                    AND cohort_fingerprint IS NOT NULL
                    AND observation_set_fingerprint IS NOT NULL
                )
                OR (
                    qualification_state = 'frozen'
                    AND JSON_LENGTH(qualification_reason_codes) > 0
                )
                SQL,
        ]);

        CanonicalSupplierSnapshotSchema::addNoMutationTriggers(
            'supplier_offer_snapshot_generations',
            'trg_snapshot_generation_no_update',
            'trg_snapshot_generation_no_delete',
        );
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::assertDestructiveDownAllowed();
        CanonicalSupplierSnapshotSchema::dropTriggers([
            'trg_snapshot_generation_no_update',
            'trg_snapshot_generation_no_delete',
        ]);
        Schema::drop('supplier_offer_snapshot_generations');
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
