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
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->unique(
                ['id', 'supplier_id', 'supplier_feed_id'],
                'uq_import_job_id_supplier_feed',
            );
        });
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::assertDestructiveDownAllowed();

        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->dropUnique('uq_import_job_id_supplier_feed');
        });
    }
};
