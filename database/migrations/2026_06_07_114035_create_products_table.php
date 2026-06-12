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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->unique();
            $table->string('supplier_sku')->nullable()->index();
            $table->string('ean')->nullable()->index();
            $table->string('mpn')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('promo_price', 12, 2)->nullable();
            $table->timestamp('promo_start')->nullable();
            $table->timestamp('promo_end')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->string('stock_status')->default('in_stock')->index();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->boolean('active')->default(false)->index();
            $table->boolean('featured')->default(false)->index();
            $table->boolean('new_product')->default(false)->index();
            $table->boolean('bestseller')->default(false)->index();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->json('specifications')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'brand_id', 'active']);
            $table->index(['active', 'featured']);
            $table->index(['active', 'stock_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
