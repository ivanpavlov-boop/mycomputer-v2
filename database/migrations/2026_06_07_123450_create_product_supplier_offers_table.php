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
        Schema::create('product_supplier_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_sku')->nullable()->index();
            $table->decimal('price', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->unsignedInteger('supplier_priority')->default(100)->index();
            $table->boolean('is_preferred')->default(false)->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['product_id', 'supplier_id', 'supplier_sku'], 'product_supplier_offer_unique');
            $table->index(['product_id', 'is_preferred']);
            $table->index(['product_id', 'price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_supplier_offers');
    }
};
