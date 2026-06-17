<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('lock_name')->default(false)->after('name')->index();
            $table->boolean('lock_seo')->default(false)->after('meta_keywords')->index();
            $table->boolean('lock_descriptions')->default(false)->after('description')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'lock_name',
                'lock_seo',
                'lock_descriptions',
            ]);
        });
    }
};
