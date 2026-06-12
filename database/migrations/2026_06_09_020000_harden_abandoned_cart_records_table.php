<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('abandoned_cart_records', 'cart_total')) {
                $table->decimal('cart_total', 12, 2)->default(0)->after('cart_snapshot');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'items_count')) {
                $table->unsignedInteger('items_count')->default(0)->after('cart_total');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'last_cart_activity_at')) {
                $table->timestamp('last_cart_activity_at')->nullable()->index()->after('items_count');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'recovery_token')) {
                $table->string('recovery_token', 80)->nullable()->unique()->after('last_cart_activity_at');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'recovery_token_expires_at')) {
                $table->timestamp('recovery_token_expires_at')->nullable()->index()->after('recovery_token');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'status')) {
                $table->string('status')->default('pending')->index()->after('recovery_token_expires_at');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'first_email_sent_at')) {
                $table->timestamp('first_email_sent_at')->nullable()->index()->after('last_email_sent_at');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'second_email_sent_at')) {
                $table->timestamp('second_email_sent_at')->nullable()->index()->after('first_email_sent_at');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'third_email_sent_at')) {
                $table->timestamp('third_email_sent_at')->nullable()->index()->after('second_email_sent_at');
            }

            if (! Schema::hasColumn('abandoned_cart_records', 'recovered_order_id')) {
                $table->foreignId('recovered_order_id')->nullable()->after('recovered_at')->constrained('orders')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            if (Schema::hasColumn('abandoned_cart_records', 'recovered_order_id')) {
                $table->dropConstrainedForeignId('recovered_order_id');
            }

            foreach ([
                'third_email_sent_at',
                'second_email_sent_at',
                'first_email_sent_at',
                'status',
                'recovery_token_expires_at',
                'recovery_token',
                'last_cart_activity_at',
                'items_count',
                'cart_total',
            ] as $column) {
                if (Schema::hasColumn('abandoned_cart_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
