<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('name_translations')->nullable()->after('name');
            $table->json('slug_translations')->nullable()->after('slug');
            $table->json('short_description_translations')->nullable()->after('short_description');
            $table->json('description_translations')->nullable()->after('description');
            $table->json('meta_title_translations')->nullable()->after('meta_title');
            $table->json('meta_description_translations')->nullable()->after('meta_description');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->json('name_translations')->nullable()->after('name');
            $table->json('slug_translations')->nullable()->after('slug');
            $table->json('description_translations')->nullable()->after('description');
            $table->json('meta_title_translations')->nullable()->after('meta_title');
            $table->json('meta_description_translations')->nullable()->after('meta_description');
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->json('description_translations')->nullable()->after('description');
            $table->json('meta_title_translations')->nullable()->after('meta_title');
            $table->json('meta_description_translations')->nullable()->after('meta_description');
        });

        Schema::table('attribute_groups', function (Blueprint $table): void {
            $table->json('name_translations')->nullable()->after('name');
            $table->json('description_translations')->nullable()->after('description');
        });

        Schema::table('product_attributes', function (Blueprint $table): void {
            $table->json('name_translations')->nullable()->after('name');
        });

        Schema::table('attribute_values', function (Blueprint $table): void {
            $table->json('value_translations')->nullable()->after('value');
        });

        Schema::table('seo_pages', function (Blueprint $table): void {
            $table->json('title_translations')->nullable()->after('title');
            $table->json('slug_translations')->nullable()->after('slug');
            $table->json('content_translations')->nullable()->after('content');
            $table->json('meta_title_translations')->nullable()->after('meta_title');
            $table->json('meta_description_translations')->nullable()->after('meta_description');
        });

        Schema::table('content_pages', function (Blueprint $table): void {
            $table->json('title_translations')->nullable()->after('title');
            $table->json('slug_translations')->nullable()->after('slug');
            $table->json('meta_title_translations')->nullable()->after('meta_title');
            $table->json('meta_description_translations')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->dropColumn([
                'title_translations',
                'slug_translations',
                'meta_title_translations',
                'meta_description_translations',
            ]);
        });

        Schema::table('seo_pages', function (Blueprint $table): void {
            $table->dropColumn([
                'title_translations',
                'slug_translations',
                'content_translations',
                'meta_title_translations',
                'meta_description_translations',
            ]);
        });

        Schema::table('attribute_values', function (Blueprint $table): void {
            $table->dropColumn('value_translations');
        });

        Schema::table('product_attributes', function (Blueprint $table): void {
            $table->dropColumn('name_translations');
        });

        Schema::table('attribute_groups', function (Blueprint $table): void {
            $table->dropColumn(['name_translations', 'description_translations']);
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn([
                'description_translations',
                'meta_title_translations',
                'meta_description_translations',
            ]);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn([
                'name_translations',
                'slug_translations',
                'description_translations',
                'meta_title_translations',
                'meta_description_translations',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'name_translations',
                'slug_translations',
                'short_description_translations',
                'description_translations',
                'meta_title_translations',
                'meta_description_translations',
            ]);
        });
    }
};
