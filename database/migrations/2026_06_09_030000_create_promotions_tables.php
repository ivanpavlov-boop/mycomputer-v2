<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            if (! Schema::hasColumn('carts', 'coupon_code')) {
                $table->string('coupon_code')->nullable()->index()->after('customer_email');
            }
        });

        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('type')->index();
            $table->string('status')->default('inactive')->index();
            $table->integer('priority')->default(0)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('stackable')->default(false)->index();
            $table->boolean('stop_further_rules')->default(false);
            $table->timestamps();
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('cart_items', 'is_gift')) {
                $table->boolean('is_gift')->default(false)->index()->after('quantity');
            }

            if (! Schema::hasColumn('cart_items', 'promotion_id')) {
                $table->foreignId('promotion_id')->nullable()->after('is_gift')->constrained('promotions')->nullOnDelete();
            }
        });

        Schema::create('promotion_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('rule_type')->index();
            $table->string('operator')->default('equals');
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('promotion_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('action_type')->index();
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->timestamps();
            $table->index(['promotion_id', 'user_id']);
            $table->index(['promotion_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            if (Schema::hasColumn('cart_items', 'promotion_id')) {
                $table->dropConstrainedForeignId('promotion_id');
            }

            if (Schema::hasColumn('cart_items', 'is_gift')) {
                $table->dropColumn('is_gift');
            }
        });

        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_actions');
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('promotions');

        Schema::table('carts', function (Blueprint $table): void {
            if (Schema::hasColumn('carts', 'coupon_code')) {
                $table->dropColumn('coupon_code');
            }
        });
    }
};
