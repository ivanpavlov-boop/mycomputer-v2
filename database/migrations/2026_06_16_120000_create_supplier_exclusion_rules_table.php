<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_exclusion_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->index();
            $table->string('ean')->nullable()->index();
            $table->string('mpn')->nullable()->index();
            $table->string('product_name_contains')->nullable();
            $table->boolean('exclude_zero_stock')->default(false);
            $table->boolean('exclude_eol')->default(false);
            $table->boolean('exclude_missing_ean')->default(false);
            $table->decimal('min_price', 12, 2)->nullable();
            $table->decimal('max_price', 12, 2)->nullable();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'is_active', 'priority'], 'supplier_exclusion_supplier_active_priority_idx');
            $table->index(['category_id', 'brand_id', 'is_active'], 'supplier_exclusion_category_brand_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_exclusion_rules');
    }
};
