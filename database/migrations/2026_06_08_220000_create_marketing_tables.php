<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->string('event_name')->index();
            $table->string('source')->default('internal')->index();
            $table->json('payload');
            $table->string('status')->default('logged')->index();
            $table->timestamps();

            $table->index(['event_name', 'source']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('feed_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_type')->index();
            $table->string('status')->default('pending')->index();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('products_count')->default(0);
            $table->timestamp('generated_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('conversion_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->string('event_name')->index();
            $table->json('payload');
            $table->json('response')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamps();

            $table->index(['provider', 'event_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_logs');
        Schema::dropIfExists('feed_exports');
        Schema::dropIfExists('marketing_events');
    }
};
