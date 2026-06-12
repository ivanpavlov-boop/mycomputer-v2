<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('inactive')->index();
            $table->json('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sync_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('erp_providers')->nullOnDelete();
            $table->string('sync_type')->index();
            $table->string('entity_type')->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id', 'status']);
        });

        Schema::create('erp_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('erp_providers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type')->index();
            $table->string('external_id')->nullable()->index();
            $table->string('document_number')->nullable()->index();
            $table->date('document_date')->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('erp_providers')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('external_product_id')->nullable()->index();
            $table->string('external_sku')->nullable()->index();
            $table->string('external_barcode')->nullable()->index();
            $table->boolean('sync_enabled')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'product_id']);
        });

        Schema::create('erp_customer_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('erp_providers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_customer_id')->nullable()->index();
            $table->string('external_company_id')->nullable()->index();
            $table->boolean('sync_enabled')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->index(['provider_id', 'user_id']);
            $table->index(['provider_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_customer_mappings');
        Schema::dropIfExists('erp_product_mappings');
        Schema::dropIfExists('erp_documents');
        Schema::dropIfExists('erp_sync_jobs');
        Schema::dropIfExists('erp_providers');
    }
};
