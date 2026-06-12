<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->boolean('import_enabled')->default(true)->index()->after('sync_strategy');
            $table->boolean('schedule_enabled')->default(false)->index()->after('import_enabled');
            $table->string('schedule_type')->default('manual_only')->index()->after('schedule_enabled');
            $table->time('morning_import_time')->nullable()->after('schedule_type');
            $table->time('evening_import_time')->nullable()->after('morning_import_time');
            $table->string('timezone')->default('Europe/Sofia')->after('evening_import_time');
            $table->unsignedInteger('stagger_minutes')->default(20)->after('timezone');
            $table->timestamp('last_import_at')->nullable()->index()->after('stagger_minutes');
            $table->timestamp('next_import_at')->nullable()->index()->after('last_import_at');
            $table->unsignedTinyInteger('maximum_product_drop_percent')->default(40)->after('next_import_at');
            $table->unsignedInteger('minimum_product_count')->default(1)->after('maximum_product_drop_percent');
            $table->boolean('allow_destructive_sync')->default(false)->after('minimum_product_count');
            $table->timestamp('last_import_notification_at')->nullable()->after('allow_destructive_sync');
        });

        Schema::create('supplier_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_feed_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_job_id')->nullable()->constrained('import_jobs')->nullOnDelete();
            $table->string('trigger_type')->index();
            $table->string('import_type')->default('xml')->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('products_seen')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_skipped')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->unsignedInteger('products_out_of_stock')->default(0);
            $table->unsignedInteger('products_needs_review')->default(0);
            $table->unsignedInteger('attributes_mapped')->default(0);
            $table->unsignedInteger('attributes_unmapped')->default(0);
            $table->unsignedInteger('availability_mapped')->default(0);
            $table->unsignedInteger('availability_unmapped')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->json('report')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['supplier_id', 'created_at']);
            $table->index(['trigger_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_import_runs');

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn([
                'import_enabled',
                'schedule_enabled',
                'schedule_type',
                'morning_import_time',
                'evening_import_time',
                'timezone',
                'stagger_minutes',
                'last_import_at',
                'next_import_at',
                'maximum_product_drop_percent',
                'minimum_product_count',
                'allow_destructive_sync',
                'last_import_notification_at',
            ]);
        });
    }
};
