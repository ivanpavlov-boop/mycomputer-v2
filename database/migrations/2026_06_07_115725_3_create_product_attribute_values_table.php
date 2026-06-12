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
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_value')->nullable();
            $table->boolean('is_filterable')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'product_attribute_id', 'attribute_value_id'], 'product_attribute_value_unique');
            $table->index(['product_attribute_id', 'attribute_value_id'], 'pav_attr_value_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
