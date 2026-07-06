<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_product_families', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_bg');
            $table->string('name_en')->nullable();
            $table->text('description_bg')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('supplier_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_key')->nullable()->index();
            $table->string('supplier_name')->nullable()->index();
            $table->string('supplier_category_name');
            $table->string('supplier_category_slug')->nullable()->index();
            $table->string('supplier_category_path')->nullable();
            $table->string('supplier_category_external_id')->nullable()->index();
            $table->string('supplier_category_hash')->unique();
            $table->foreignId('canonical_product_family_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('status')->default('pending_review')->index();
            $table->string('confidence')->nullable()->index();
            $table->text('match_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['supplier_id', 'status'], 'supplier_category_mapping_supplier_status_idx');
            $table->index(['canonical_product_family_id', 'status'], 'supplier_category_mapping_family_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_category_mappings');
        Schema::dropIfExists('canonical_product_families');
    }
};
