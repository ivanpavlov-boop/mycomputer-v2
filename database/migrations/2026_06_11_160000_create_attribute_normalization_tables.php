<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('group_name')->nullable()->index();
            $table->string('type')->default('text')->index();
            $table->string('unit')->nullable();
            $table->boolean('is_filterable')->default(true)->index();
            $table->boolean('is_comparable')->default(true)->index();
            $table->boolean('is_required')->default(false);
            $table->json('category_scope')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('attribute_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->index();
            $table->string('locale')->nullable()->index();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type')->nullable()->index();
            $table->unsignedTinyInteger('confidence')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['canonical_attribute_id', 'normalized_alias', 'supplier_id'], 'attribute_alias_unique');
            $table->index(['normalized_alias', 'supplier_id', 'is_active'], 'attribute_alias_lookup');
        });

        Schema::create('canonical_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('normalized_value');
            $table->string('display_value');
            $table->decimal('numeric_value', 16, 4)->nullable();
            $table->string('unit')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['canonical_attribute_id', 'normalized_value'], 'canonical_attribute_value_unique');
            $table->index(['canonical_attribute_id', 'sort_order'], 'canonical_attribute_value_sort');
        });

        Schema::create('attribute_value_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_attribute_value_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->index();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale')->nullable()->index();
            $table->unsignedTinyInteger('confidence')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['canonical_attribute_value_id', 'normalized_alias', 'supplier_id'], 'attribute_value_alias_unique');
            $table->index(['normalized_alias', 'supplier_id', 'is_active'], 'attribute_value_alias_lookup');
        });

        Schema::create('supplier_product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type')->index();
            $table->string('source_code')->nullable()->index();
            $table->string('raw_name');
            $table->string('raw_value');
            $table->string('raw_unit')->nullable();
            $table->foreignId('canonical_attribute_id')->nullable()->constrained('canonical_attributes')->nullOnDelete();
            $table->foreignId('canonical_attribute_value_id')->nullable()->constrained('canonical_attribute_values')->nullOnDelete();
            $table->string('normalized_name')->nullable()->index();
            $table->string('normalized_value')->nullable()->index();
            $table->unsignedTinyInteger('confidence')->default(0)->index();
            $table->string('status')->default('unmapped')->index();
            $table->timestamps();

            $table->index(['supplier_product_id', 'status'], 'supplier_product_attribute_status');
            $table->index(['source_type', 'source_code', 'status'], 'supplier_attribute_source_status');
        });

        Schema::create('attribute_mapping_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->index();
            $table->string('source_code')->nullable()->index();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('raw_name');
            $table->string('raw_value');
            $table->foreignId('mapped_attribute_id')->nullable()->constrained('canonical_attributes')->nullOnDelete();
            $table->foreignId('mapped_value_id')->nullable()->constrained('canonical_attribute_values')->nullOnDelete();
            $table->unsignedTinyInteger('confidence')->default(0)->index();
            $table->string('action')->index();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('category_attribute_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_attribute_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'canonical_attribute_id'], 'category_attribute_template_unique');
            $table->index(['category_id', 'sort_order'], 'category_attribute_template_sort');
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->foreignId('canonical_attribute_id')->nullable()->after('product_attribute_id')->constrained('canonical_attributes')->nullOnDelete();
            $table->foreignId('canonical_attribute_value_id')->nullable()->after('canonical_attribute_id')->constrained('canonical_attribute_values')->nullOnDelete();
            $table->index(['canonical_attribute_id', 'canonical_attribute_value_id'], 'product_canonical_attribute_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropIndex('product_canonical_attribute_idx');
            $table->dropConstrainedForeignId('canonical_attribute_value_id');
            $table->dropConstrainedForeignId('canonical_attribute_id');
        });

        Schema::dropIfExists('category_attribute_templates');
        Schema::dropIfExists('attribute_mapping_logs');
        Schema::dropIfExists('supplier_product_attributes');
        Schema::dropIfExists('attribute_value_aliases');
        Schema::dropIfExists('canonical_attribute_values');
        Schema::dropIfExists('attribute_aliases');
        Schema::dropIfExists('canonical_attributes');
    }
};
