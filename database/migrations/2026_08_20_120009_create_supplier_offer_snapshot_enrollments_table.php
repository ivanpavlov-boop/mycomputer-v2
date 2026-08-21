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
        Schema::create('supplier_offer_snapshot_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('supplier_feed_id');
            CanonicalSupplierSnapshotSchema::ascii($table->string('source_identity', 128));
            CanonicalSupplierSnapshotSchema::ascii($table->char('supplier_sku_hash', 64));
            $table->unsignedBigInteger('effective_import_history_id');
            CanonicalSupplierSnapshotSchema::ascii($table->string('enrollment_source', 48));
            CanonicalSupplierSnapshotSchema::ascii($table->char('enrollment_fingerprint', 64));
            CanonicalSupplierSnapshotSchema::ascii($table->char('enrolled_at', 25));
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['supplier_id', 'source_identity', 'supplier_sku_hash'],
                'uq_snapshot_enrollment_scope_offer',
            );
            $table->index(
                ['supplier_feed_id', 'effective_import_history_id'],
                'ix_snapshot_enrollment_feed',
            );
            $table->index(
                'effective_import_history_id',
                'ix_snapshot_enrollment_effective_history',
            );
            $table->index(
                ['supplier_id', 'source_identity', 'effective_import_history_id', 'supplier_sku_hash'],
                'ix_snapshot_enrollment_effective',
            );

            $table->foreign('supplier_id', 'fk_snapshot_enrollment_supplier')
                ->references('id')->on('suppliers')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign('supplier_feed_id', 'fk_snapshot_enrollment_feed')
                ->references('id')->on('supplier_feeds')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(
                'effective_import_history_id',
                'fk_snapshot_enrollment_effective_history',
            )->references('id')->on('import_histories')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_offer_snapshot_enrollments', [
            'chk_snapshot_enrollment_supplier_sku_hash' => self::requiredHex('supplier_sku_hash'),
            'chk_snapshot_enrollment_fingerprint' => self::requiredHex('enrollment_fingerprint'),
            'chk_snapshot_enrollment_source_identity' => <<<'SQL'
                OCTET_LENGTH(source_identity) BETWEEN 1 AND 128
                AND REGEXP_LIKE(
                    source_identity,
                    _ascii'^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$',
                    'c'
                )
                SQL,
            'chk_snapshot_enrollment_source' => <<<'SQL'
                BINARY enrollment_source IN (
                    BINARY _ascii'capture_start_seed',
                    BINARY _ascii'exact_source_observation',
                    BINARY _ascii'capture_start_seed_and_exact_source_observation'
                )
                SQL,
            'chk_snapshot_enrollment_timestamp' => <<<'SQL'
                REGEXP_LIKE(enrolled_at, _ascii'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$', 'c')
                SQL,
        ]);

        CanonicalSupplierSnapshotSchema::addNoMutationTriggers(
            'supplier_offer_snapshot_enrollments',
            'trg_snapshot_enrollment_no_update',
            'trg_snapshot_enrollment_no_delete',
        );
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
            '2026_08_20_120009_create_supplier_offer_snapshot_enrollments_table',
            function (): void {
                CanonicalSupplierSnapshotSchema::dropTriggers([
                    'trg_snapshot_enrollment_no_update',
                    'trg_snapshot_enrollment_no_delete',
                ]);
                Schema::drop('supplier_offer_snapshot_enrollments');
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
