<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const FOREIGN_KEYS = [
        'import_job_id' => 'import_jobs',
        'supplier_id' => 'suppliers',
        'supplier_feed_id' => 'supplier_feeds',
    ];

    public function up(): void
    {
        Schema::table('import_histories', function (Blueprint $table): void {
            foreach (array_keys(self::FOREIGN_KEYS) as $column) {
                $table->dropForeign([$column]);
            }

            foreach (self::FOREIGN_KEYS as $column => $parentTable) {
                $table->foreign($column)
                    ->references('id')
                    ->on($parentTable)
                    ->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_histories', function (Blueprint $table): void {
            foreach (array_keys(self::FOREIGN_KEYS) as $column) {
                $table->dropForeign([$column]);
            }

            $table->foreign('import_job_id')
                ->references('id')
                ->on('import_jobs')
                ->nullOnDelete();
            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->cascadeOnDelete();
            $table->foreign('supplier_feed_id')
                ->references('id')
                ->on('supplier_feeds')
                ->nullOnDelete();
        });
    }
};
