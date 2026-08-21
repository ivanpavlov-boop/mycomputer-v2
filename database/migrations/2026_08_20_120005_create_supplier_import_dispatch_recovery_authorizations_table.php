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
        Schema::create('supplier_import_dispatch_recovery_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_import_execution_claim_id');
            $table->unsignedBigInteger('supplier_import_dispatch_outbox_id');
            CanonicalSupplierSnapshotSchema::ascii($table->char('logical_execution_key', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->string('target_parent_type', 32));
            $table->unsignedBigInteger('target_parent_id');
            CanonicalSupplierSnapshotSchema::ascii($table->string('authorization_action', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->char('expected_state_fingerprint', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->string('canonical_reason_code', 96));
            $table->unsignedBigInteger('authorized_operator_id');
            $table->timestamp('authorized_at', 6);
            $table->timestamp('expires_at', 6);
            CanonicalSupplierSnapshotSchema::ascii($table->char('authorization_nonce_hash', 64));

            $table->unique('authorization_nonce_hash', 'uq_import_recovery_auth_nonce');
            $table->unique([
                'id',
                'authorization_action',
                'authorized_operator_id',
                'supplier_import_execution_claim_id',
                'supplier_import_dispatch_outbox_id',
                'logical_execution_key',
                'target_parent_type',
                'target_parent_id',
            ], 'uq_import_recovery_auth_complete_tuple');
            $table->index(
                ['supplier_import_execution_claim_id', 'authorized_at', 'id'],
                'ix_import_recovery_auth_claim',
            );
            $table->index(
                ['supplier_import_dispatch_outbox_id', 'supplier_import_execution_claim_id'],
                'ix_import_recovery_auth_outbox_claim',
            );
            $table->index(
                ['authorized_operator_id', 'authorized_at', 'id'],
                'ix_import_recovery_auth_operator',
            );

            $table->foreign(
                'supplier_import_execution_claim_id',
                'fk_import_recovery_auth_claim',
            )->references('id')->on('supplier_import_execution_claims')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(
                ['supplier_import_dispatch_outbox_id', 'supplier_import_execution_claim_id'],
                'fk_import_recovery_auth_outbox_claim',
            )->references(['id', 'supplier_import_execution_claim_id'])
                ->on('supplier_import_dispatch_outbox')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->foreign('authorized_operator_id', 'fk_import_recovery_auth_operator')
                ->references('id')->on('users')->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_import_dispatch_recovery_authorizations', [
            'chk_import_recovery_auth_action' => <<<'SQL'
                BINARY authorization_action IN (
                    BINARY _ascii'republish_same_key',
                    BINARY _ascii'recover_expired_queued_ownership',
                    BINARY _ascii'terminalize_stale_dispatch',
                    BINARY _ascii'terminalize_publication_mismatch',
                    BINARY _ascii'terminalize_abandoned_processing'
                )
                SQL,
            'chk_import_recovery_auth_logical_key' => self::requiredHex('logical_execution_key'),
            'chk_import_recovery_auth_parent_type' => <<<'SQL'
                BINARY target_parent_type IN (
                    BINARY _ascii'supplier_import_run',
                    BINARY _ascii'supplier_feed'
                )
                SQL,
            'chk_import_recovery_auth_parent_id' => 'target_parent_id > 0',
            'chk_import_recovery_auth_expected_fingerprint' => self::requiredHex('expected_state_fingerprint'),
            'chk_import_recovery_auth_nonce_hash' => self::requiredHex('authorization_nonce_hash'),
            'chk_import_recovery_auth_expiry' => 'expires_at = TIMESTAMPADD(SECOND, 900, authorized_at)',
        ]);

        CanonicalSupplierSnapshotSchema::addNoMutationTriggers(
            'supplier_import_dispatch_recovery_authorizations',
            'trg_import_recovery_auth_no_update',
            'trg_import_recovery_auth_no_delete',
        );
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
            '2026_08_20_120005_create_supplier_import_dispatch_recovery_authorizations_table',
            function (): void {
                CanonicalSupplierSnapshotSchema::dropTriggers([
                    'trg_import_recovery_auth_no_update',
                    'trg_import_recovery_auth_no_delete',
                ]);
                Schema::drop('supplier_import_dispatch_recovery_authorizations');
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
