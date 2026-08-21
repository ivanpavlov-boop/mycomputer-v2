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
        Schema::create('supplier_import_cohort_authorization_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_import_execution_claim_id');
            CanonicalSupplierSnapshotSchema::ascii($table->char('supplier_sku_hash', 64));
            $table->timestamp('created_at', 6)->useCurrent();

            $table->unique(
                ['supplier_import_execution_claim_id', 'supplier_sku_hash'],
                'uq_import_cohort_auth_claim_offer',
            );
            $table->foreign(
                'supplier_import_execution_claim_id',
                'fk_import_cohort_auth_claim',
            )->references('id')->on('supplier_import_execution_claims')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_import_cohort_authorization_members', [
            'chk_import_cohort_auth_supplier_sku_hash' => <<<'SQL'
                OCTET_LENGTH(supplier_sku_hash) = 64
                AND REGEXP_LIKE(supplier_sku_hash, _ascii'^[0-9a-f]{64}$', 'c')
                SQL,
        ]);

        CanonicalSupplierSnapshotSchema::addNoMutationTriggers(
            'supplier_import_cohort_authorization_members',
            'trg_import_cohort_auth_no_update',
            'trg_import_cohort_auth_no_delete',
        );
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
            '2026_08_20_120007_create_supplier_import_cohort_authorization_members_table',
            function (): void {
                CanonicalSupplierSnapshotSchema::dropTriggers([
                    'trg_import_cohort_auth_no_update',
                    'trg_import_cohort_auth_no_delete',
                ]);
                Schema::drop('supplier_import_cohort_authorization_members');
            },
        );
    }
};
