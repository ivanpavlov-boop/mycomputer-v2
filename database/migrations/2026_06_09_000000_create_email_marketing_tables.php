<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('source')->default('newsletter')->index();
            $table->string('status')->default('subscribed')->index();
            $table->boolean('gdpr_consent')->default(false);
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->string('template');
            $table->string('status')->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('provider')->index();
            $table->string('type')->index();
            $table->string('subject');
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_automations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('trigger')->index();
            $table->boolean('enabled')->default(true)->index();
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        Schema::create('abandoned_cart_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->json('cart_snapshot');
            $table->timestamp('last_email_sent_at')->nullable();
            $table->unsignedTinyInteger('emails_sent')->default(0);
            $table->timestamp('recovered_at')->nullable()->index();
            $table->decimal('recovered_revenue', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('product_price_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_price', 12, 2)->nullable();
            $table->timestamp('triggered_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['email', 'product_id']);
        });

        Schema::create('product_stock_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('triggered_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['email', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_alerts');
        Schema::dropIfExists('product_price_alerts');
        Schema::dropIfExists('abandoned_cart_records');
        Schema::dropIfExists('email_automations');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_subscribers');
    }
};
