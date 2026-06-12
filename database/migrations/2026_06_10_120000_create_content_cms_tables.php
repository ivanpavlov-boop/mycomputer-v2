<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('template_data');
            $table->timestamps();
        });

        Schema::create('reusable_content_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('block_type')->index();
            $table->json('settings')->nullable();
            $table->json('content')->nullable();
            $table->json('responsive_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('content_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('page_type')->index();
            $table->string('status')->default('draft')->index();
            $table->foreignId('template_id')->nullable()->constrained('content_templates')->nullOnDelete();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(['page_type', 'status', 'published_at']);
        });

        Schema::create('content_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reusable_block_id')->nullable()->constrained('reusable_content_blocks')->nullOnDelete();
            $table->string('block_type')->index();
            $table->string('title')->nullable();
            $table->json('settings')->nullable();
            $table->json('content')->nullable();
            $table->json('responsive_settings')->nullable();
            $table->json('visibility_rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamps();

            $table->index(['content_page_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('reusable_content_blocks');
        Schema::dropIfExists('content_templates');
    }
};
