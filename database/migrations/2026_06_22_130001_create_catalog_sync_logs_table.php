<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_sync_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('status')->index();
            $table->string('reason')->nullable()->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['catalog_sync_batch_id', 'status']);
            $table->index(['supplier_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sync_logs');
    }
};
