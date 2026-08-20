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
        Schema::create('supplier_offer_snapshot_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('snapshot_generation_id');
            $table->unsignedBigInteger('snapshot_enrollment_id');
            CanonicalSupplierSnapshotSchema::ascii($table->char('supplier_sku_hash', 64));
            $table->boolean('present');
            $table->decimal('price', 12, 2)->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('currency', 3))->nullable();
            $table->unsignedInteger('raw_quantity_observed')->nullable();
            $table->unsignedTinyInteger('eol_flag')->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('canonical_public_status', 48))->nullable();
            $table->boolean('supplier_mapper_valid')->default(false);
            $table->boolean('exact_supplier_sku_match')->default(false);
            $table->boolean('identifier_conflict')->default(false);
            $table->boolean('blocking_validation_issue')->default(false);
            $table->boolean('duplicate_offer')->default(false);
            CanonicalSupplierSnapshotSchema::ascii($table->char('reliable_manufacturer_mpn_hash', 64))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->char('observation_fingerprint', 64));
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['snapshot_generation_id', 'snapshot_enrollment_id'],
                'uq_snapshot_observation_generation_enrollment',
            );
            $table->unique(
                ['snapshot_generation_id', 'supplier_sku_hash'],
                'uq_snapshot_observation_generation_offer',
            );
            $table->index(
                ['snapshot_enrollment_id', 'snapshot_generation_id'],
                'ix_snapshot_observation_enrollment_history',
            );
            $table->index(
                ['supplier_sku_hash', 'snapshot_generation_id'],
                'ix_snapshot_observation_offer_history',
            );

            $table->foreign(
                'snapshot_generation_id',
                'fk_snapshot_observation_generation',
            )->references('id')->on('supplier_offer_snapshot_generations')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(
                'snapshot_enrollment_id',
                'fk_snapshot_observation_enrollment',
            )->references('id')->on('supplier_offer_snapshot_enrollments')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_offer_snapshot_observations', [
            'chk_snapshot_observation_supplier_sku_hash' => self::requiredHex('supplier_sku_hash'),
            'chk_snapshot_observation_fingerprint' => self::requiredHex('observation_fingerprint'),
            'chk_snapshot_observation_mpn_hash' => self::nullableHex('reliable_manufacturer_mpn_hash'),
            'chk_snapshot_observation_boolean_domains' => <<<'SQL'
                present IN (0, 1)
                AND supplier_mapper_valid IN (0, 1)
                AND exact_supplier_sku_match IN (0, 1)
                AND identifier_conflict IN (0, 1)
                AND blocking_validation_issue IN (0, 1)
                AND duplicate_offer IN (0, 1)
                AND (eol_flag IS NULL OR eol_flag IN (0, 1))
                SQL,
            'chk_snapshot_observation_price' => 'price IS NULL OR price >= 0',
            'chk_snapshot_observation_currency' => <<<'SQL'
                currency IS NULL
                OR (
                    OCTET_LENGTH(currency) = 3
                    AND REGEXP_LIKE(currency, _ascii'^[A-Z]{3}$', 'c')
                )
                SQL,
            'chk_snapshot_observation_absent_semantics' => <<<'SQL'
                present = 1
                OR (
                    price IS NULL
                    AND currency IS NULL
                    AND raw_quantity_observed IS NULL
                    AND eol_flag IS NULL
                    AND canonical_public_status IS NULL
                    AND supplier_mapper_valid = 0
                    AND exact_supplier_sku_match = 0
                    AND identifier_conflict = 0
                    AND blocking_validation_issue = 0
                    AND duplicate_offer = 0
                    AND reliable_manufacturer_mpn_hash IS NULL
                )
                SQL,
        ]);

        CanonicalSupplierSnapshotSchema::addNoMutationTriggers(
            'supplier_offer_snapshot_observations',
            'trg_snapshot_observation_no_update',
            'trg_snapshot_observation_no_delete',
        );
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::assertDestructiveDownAllowed();
        CanonicalSupplierSnapshotSchema::dropTriggers([
            'trg_snapshot_observation_no_update',
            'trg_snapshot_observation_no_delete',
        ]);
        Schema::drop('supplier_offer_snapshot_observations');
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
