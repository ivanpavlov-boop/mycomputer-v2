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
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_feed_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_sku')->nullable()->index();
            $table->string('ean')->nullable()->index();
            $table->string('mpn')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('brand_name')->nullable()->index();
            $table->string('category_name')->nullable()->index();
            $table->decimal('price', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('currency', 3)->default('BGN');
            $table->json('raw_data');
            $table->string('payload_hash')->index();
            $table->timestamp('received_at')->index();
            $table->timestamp('synced_at')->nullable()->index();
            $table->string('status')->default('new')->index();
            $table->text('mapping_notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'received_at']);
            $table->index(['supplier_feed_id', 'status']);
            $table->index(['product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
