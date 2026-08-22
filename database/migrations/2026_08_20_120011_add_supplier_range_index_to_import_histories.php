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
        Schema::table('import_histories', function (Blueprint $table): void {
            $table->index(['supplier_id', 'id'], 'ix_import_history_supplier_id');
        });
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
            '2026_08_20_120011_add_supplier_range_index_to_import_histories',
            function (): void {
                Schema::table('import_histories', function (Blueprint $table): void {
                    $table->dropIndex('ix_import_history_supplier_id');
                });
            },
        );
    }
};
