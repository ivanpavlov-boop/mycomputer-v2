<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('failed_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_feed_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_sku')->nullable()->index();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('error_type')->default('validation')->index();
            $table->text('error_message');
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_imports');
    }
};
