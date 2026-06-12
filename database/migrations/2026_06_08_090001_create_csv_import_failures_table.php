<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_import_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_import_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number')->index();
            $table->string('error_type')->index();
            $table->text('error_message');
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['csv_import_job_id', 'error_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_failures');
    }
};
