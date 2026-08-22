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
        Schema::create('supplier_import_dispatch_alert_intents', function (Blueprint $table): void {
            $table->id();
            CanonicalSupplierSnapshotSchema::ascii($table->char('alert_identity', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->string('schema_version', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->string('alert_type', 64));
            $table->unsignedBigInteger('dispatch_outbox_id');
            $table->timestamp('delivery_watchdog_at', 6);
            CanonicalSupplierSnapshotSchema::ascii($table->string('severity', 16));
            $table->unsignedInteger('critical_bucket')->nullable();
            $table->json('payload');
            CanonicalSupplierSnapshotSchema::ascii($table->string('delivery_state', 40))->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('delivery_generation')->default(0);
            CanonicalSupplierSnapshotSchema::ascii($table->char('delivery_owner_token_hash', 64))->nullable();
            $table->timestamp('delivery_lease_acquired_at', 6)->nullable();
            $table->timestamp('delivery_lease_expires_at', 6)->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('acknowledged_at', 6)->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('last_failure_code', 64))->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

            $table->unique('alert_identity', 'uq_import_dispatch_alert_identity');
            $table->index(
                ['dispatch_outbox_id', 'created_at', 'id'],
                'ix_import_dispatch_alert_outbox',
            );
            $table->index(
                ['delivery_state', 'next_attempt_at', 'id'],
                'ix_import_dispatch_alert_due',
            );
            $table->index(
                ['delivery_state', 'delivery_lease_expires_at', 'id'],
                'ix_import_dispatch_alert_lease',
            );
            $table->foreign('dispatch_outbox_id', 'fk_import_dispatch_alert_outbox')
                ->references('id')->on('supplier_import_dispatch_outbox')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_import_dispatch_alert_intents', [
            'chk_import_dispatch_alert_identity' => self::requiredHex('alert_identity'),
            'chk_import_dispatch_alert_schema_type' => <<<'SQL'
                BINARY schema_version = BINARY _ascii'supplier-import-dispatch-alert-v1'
                AND OCTET_LENGTH(schema_version) = 33
                AND BINARY alert_type = BINARY _ascii'dispatch_watchdog_overdue'
                AND OCTET_LENGTH(alert_type) = 25
                AND JSON_TYPE(payload) = 'OBJECT'
                SQL,
            'chk_import_dispatch_alert_severity_bucket' => <<<'SQL'
                (
                    BINARY severity = BINARY _ascii'warning'
                    AND critical_bucket IS NULL
                )
                OR (
                    BINARY severity = BINARY _ascii'critical'
                    AND critical_bucket IS NOT NULL
                )
                SQL,
            'chk_import_dispatch_alert_state' => <<<'SQL'
                BINARY delivery_state IN (
                    BINARY _ascii'pending',
                    BINARY _ascii'delivering',
                    BINARY _ascii'acknowledged',
                    BINARY _ascii'permanent_failed',
                    BINARY _ascii'delivery_outcome_unknown_exhausted'
                )
                SQL,
            'chk_import_dispatch_alert_attempt_bound' => 'attempt_count >= 0 AND attempt_count <= 8',
            'chk_import_dispatch_alert_delivery_owner_tuple' => <<<'SQL'
                (
                    delivery_owner_token_hash IS NULL
                    AND delivery_lease_acquired_at IS NULL
                    AND delivery_lease_expires_at IS NULL
                )
                OR (
                    delivery_owner_token_hash IS NOT NULL
                    AND OCTET_LENGTH(delivery_owner_token_hash) = 64
                    AND REGEXP_LIKE(delivery_owner_token_hash, _ascii'^[0-9a-f]{64}$', 'c')
                    AND delivery_lease_acquired_at IS NOT NULL
                    AND delivery_lease_expires_at IS NOT NULL
                    AND delivery_lease_acquired_at < delivery_lease_expires_at
                )
                SQL,
            'chk_import_dispatch_alert_state_tuple' => <<<'SQL'
                (
                    delivery_state = 'pending'
                    AND delivery_owner_token_hash IS NULL
                    AND delivery_lease_acquired_at IS NULL
                    AND delivery_lease_expires_at IS NULL
                    AND next_attempt_at IS NOT NULL
                    AND acknowledged_at IS NULL
                )
                OR (
                    delivery_state = 'delivering'
                    AND delivery_owner_token_hash IS NOT NULL
                    AND delivery_lease_acquired_at IS NOT NULL
                    AND delivery_lease_expires_at IS NOT NULL
                    AND next_attempt_at IS NULL
                    AND acknowledged_at IS NULL
                    AND attempt_count BETWEEN 1 AND 8
                )
                OR (
                    delivery_state = 'acknowledged'
                    AND delivery_owner_token_hash IS NULL
                    AND delivery_lease_acquired_at IS NULL
                    AND delivery_lease_expires_at IS NULL
                    AND next_attempt_at IS NULL
                    AND acknowledged_at IS NOT NULL
                    AND last_failure_code IS NULL
                )
                OR (
                    delivery_state = 'permanent_failed'
                    AND delivery_owner_token_hash IS NULL
                    AND delivery_lease_acquired_at IS NULL
                    AND delivery_lease_expires_at IS NULL
                    AND next_attempt_at IS NULL
                    AND acknowledged_at IS NULL
                    AND attempt_count BETWEEN 1 AND 8
                    AND last_failure_code IS NOT NULL
                )
                OR (
                    delivery_state = 'delivery_outcome_unknown_exhausted'
                    AND delivery_owner_token_hash IS NULL
                    AND delivery_lease_acquired_at IS NULL
                    AND delivery_lease_expires_at IS NULL
                    AND next_attempt_at IS NULL
                    AND acknowledged_at IS NULL
                    AND attempt_count = 8
                    AND BINARY last_failure_code = BINARY _ascii'alert_delivery_outcome_unknown_exhausted'
                )
                SQL,
        ]);
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
            '2026_08_20_120004_create_supplier_import_dispatch_alert_intents_table',
            function (): void {
                Schema::table('supplier_import_dispatch_alert_intents', function (Blueprint $table): void {
                    $table->dropForeign('fk_import_dispatch_alert_outbox');
                });
                Schema::drop('supplier_import_dispatch_alert_intents');
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
};
