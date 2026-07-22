<?php

use App\Enums\CategoryAttributeFilterControl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_product_attributes', function (Blueprint $table): void {
            $table->string('filter_control_type', 24)
                ->default(CategoryAttributeFilterControl::Auto->value)
                ->after('is_filterable');
        });
    }

    public function down(): void
    {
        Schema::table('category_product_attributes', function (Blueprint $table): void {
            $table->dropColumn('filter_control_type');
        });
    }
};
