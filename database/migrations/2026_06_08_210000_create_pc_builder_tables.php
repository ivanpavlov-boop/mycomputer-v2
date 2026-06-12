<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_builds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamps();

            $table->index(['user_id', 'session_id']);
        });

        Schema::create('pc_build_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pc_build_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('component_type')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['pc_build_id', 'product_id', 'component_type']);
        });

        Schema::create('pc_compatibility_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_type')->index();
            $table->string('source_attribute');
            $table->string('target_attribute');
            $table->string('operator')->default('equals');
            $table->string('value')->nullable();
            $table->unsignedInteger('priority')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_compatibility_rules');
        Schema::dropIfExists('pc_build_items');
        Schema::dropIfExists('pc_builds');
    }
};
