<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            if (! Schema::hasColumn('product_attributes', 'code')) {
                $table->string('code')->nullable()->after('id');
            }

            if (! Schema::hasColumn('product_attributes', 'name_bg')) {
                $table->string('name_bg')->nullable()->after('name');
            }

            if (! Schema::hasColumn('product_attributes', 'name_en')) {
                $table->string('name_en')->nullable()->after('name_bg');
            }

            if (! Schema::hasColumn('product_attributes', 'description_bg')) {
                $table->text('description_bg')->nullable()->after('name_en');
            }

            if (! Schema::hasColumn('product_attributes', 'description_en')) {
                $table->text('description_en')->nullable()->after('description_bg');
            }

            if (! Schema::hasColumn('product_attributes', 'is_visible_on_product')) {
                $table->boolean('is_visible_on_product')->default(true)->after('is_filterable');
            }

            if (! Schema::hasColumn('product_attributes', 'is_comparable')) {
                $table->boolean('is_comparable')->default(false)->after('is_visible_on_product');
            }

            if (! Schema::hasColumn('product_attributes', 'is_required_by_default')) {
                $table->boolean('is_required_by_default')->default(false)->after('is_required');
            }
        });

        DB::table('product_attributes')
            ->select(['id', 'slug', 'name', 'name_translations'])
            ->orderBy('id')
            ->get()
            ->each(function (object $attribute): void {
                $translations = json_decode((string) $attribute->name_translations, true);
                $code = filled($attribute->slug) ? $attribute->slug : Str::slug((string) $attribute->name);

                DB::table('product_attributes')
                    ->where('id', $attribute->id)
                    ->update([
                        'code' => $code ?: 'attribute-'.$attribute->id,
                        'name_bg' => $attribute->name,
                        'name_en' => is_array($translations) ? ($translations['en'] ?? null) : null,
                    ]);
            });

        Schema::table('product_attributes', function (Blueprint $table) {
            if (! $this->indexExists('product_attributes', 'product_attributes_code_unique')) {
                $table->unique('code', 'product_attributes_code_unique');
            }

            if (! $this->indexExists('product_attributes', 'product_attributes_flags_idx')) {
                $table->index(['is_filterable', 'is_visible_on_product', 'is_active'], 'product_attributes_flags_idx');
            }
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            if (! Schema::hasColumn('product_attribute_values', 'value_text')) {
                $table->text('value_text')->nullable()->after('custom_value');
            }

            if (! Schema::hasColumn('product_attribute_values', 'value_number')) {
                $table->decimal('value_number', 16, 4)->nullable()->after('value_text');
            }

            if (! Schema::hasColumn('product_attribute_values', 'value_boolean')) {
                $table->boolean('value_boolean')->nullable()->after('value_number');
            }

            if (! Schema::hasColumn('product_attribute_values', 'value_json')) {
                $table->json('value_json')->nullable()->after('value_boolean');
            }

            if (! Schema::hasColumn('product_attribute_values', 'unit')) {
                $table->string('unit')->nullable()->after('value_json');
            }

            if (! Schema::hasColumn('product_attribute_values', 'source')) {
                $table->string('source')->default('manual')->after('unit')->index();
            }

            if (! Schema::hasColumn('product_attribute_values', 'is_verified')) {
                $table->boolean('is_verified')->default(false)->after('source')->index();
            }

            if (! Schema::hasColumn('product_attribute_values', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_verified')->index();
            }
        });

        if (! Schema::hasTable('category_product_attributes')) {
            Schema::create('category_product_attributes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_required')->default(false);
                $table->boolean('is_filterable')->default(false);
                $table->boolean('is_visible_on_product')->default(true);
                $table->boolean('is_comparable')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['category_id', 'product_attribute_id'], 'category_product_attribute_unique');
                $table->index(['category_id', 'sort_order'], 'category_product_attribute_sort');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product_attributes');

        Schema::table('product_attribute_values', function (Blueprint $table) {
            foreach ([
                'sort_order',
                'is_verified',
                'source',
                'unit',
                'value_json',
                'value_boolean',
                'value_number',
                'value_text',
            ] as $column) {
                if (Schema::hasColumn('product_attribute_values', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            if ($this->indexExists('product_attributes', 'product_attributes_flags_idx')) {
                $table->dropIndex('product_attributes_flags_idx');
            }

            if ($this->indexExists('product_attributes', 'product_attributes_code_unique')) {
                $table->dropUnique('product_attributes_code_unique');
            }

            foreach ([
                'is_required_by_default',
                'is_comparable',
                'is_visible_on_product',
                'description_en',
                'description_bg',
                'name_en',
                'name_bg',
                'code',
            ] as $column) {
                if (Schema::hasColumn('product_attributes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $definition): bool => ($definition['name'] ?? null) === $index);
    }
};
