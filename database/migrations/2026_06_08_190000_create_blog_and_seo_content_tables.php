<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blog_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('featured_image')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->unsignedInteger('reading_time')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
        });

        Schema::create('blog_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('blog_post_tag', function (Blueprint $table): void {
            $table->foreignId('blog_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blog_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['blog_post_id', 'blog_tag_id']);
        });

        Schema::create('seo_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type')->index();
            $table->longText('content');
            $table->string('status')->default('draft')->index();
            $table->foreignId('related_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('related_brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->string('schema_type')->nullable();
            $table->json('schema_data')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
        });

        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('source_url')->unique();
            $table->string('target_url');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('seo_metadata', function (Blueprint $table): void {
            $table->id();
            $table->string('metadatable_type');
            $table->unsignedBigInteger('metadatable_id');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->string('schema_type')->nullable();
            $table->json('schema_data')->nullable();
            $table->timestamps();

            $table->unique(['metadatable_type', 'metadatable_id']);
        });

        foreach (['blog_posts', 'seo_pages'] as $tableName) {
            $prefix = $tableName === 'blog_posts' ? 'blog_post' : 'seo_page';

            Schema::create($prefix.'_product', function (Blueprint $table) use ($prefix, $tableName): void {
                $table->foreignId($prefix.'_id')->constrained($tableName)->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->primary([$prefix.'_id', 'product_id']);
            });

            Schema::create($prefix.'_category', function (Blueprint $table) use ($prefix, $tableName): void {
                $table->foreignId($prefix.'_id')->constrained($tableName)->cascadeOnDelete();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->primary([$prefix.'_id', 'category_id']);
            });

            Schema::create($prefix.'_brand', function (Blueprint $table) use ($prefix, $tableName): void {
                $table->foreignId($prefix.'_id')->constrained($tableName)->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->primary([$prefix.'_id', 'brand_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_page_brand');
        Schema::dropIfExists('seo_page_category');
        Schema::dropIfExists('seo_page_product');
        Schema::dropIfExists('blog_post_brand');
        Schema::dropIfExists('blog_post_category');
        Schema::dropIfExists('blog_post_product');
        Schema::dropIfExists('seo_metadata');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('seo_pages');
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_tags');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
    }
};
