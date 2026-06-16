<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('scope_type')->index();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('margin_type')->default('percentage')->index();
            $table->decimal('margin_value', 12, 4)->default(0);
            $table->decimal('minimum_margin', 12, 2)->nullable();
            $table->decimal('minimum_final_price', 12, 2)->nullable();
            $table->string('rounding_rule')->default('none');
            $table->string('msrp_strategy')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->index(['scope_type', 'is_active']);
            $table->index(['product_id', 'is_active']);
            $table->index(['category_id', 'is_active']);
            $table->index(['supplier_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
