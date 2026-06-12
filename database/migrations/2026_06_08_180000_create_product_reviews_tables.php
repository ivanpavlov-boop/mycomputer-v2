<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('comment');
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();
            $table->boolean('is_verified_purchase')->default(false)->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'user_id']);
            $table->unique(['product_id', 'customer_email']);
            $table->index(['product_id', 'status', 'rating']);
        });

        Schema::create('product_review_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->string('vote_type');
            $table->timestamps();

            $table->unique(['product_review_id', 'user_id']);
            $table->unique(['product_review_id', 'session_id']);
            $table->index(['product_review_id', 'vote_type']);
        });

        Schema::create('product_review_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->string('reason');
            $table->text('message')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamps();

            $table->index(['product_review_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_review_reports');
        Schema::dropIfExists('product_review_votes');
        Schema::dropIfExists('product_reviews');
    }
};
