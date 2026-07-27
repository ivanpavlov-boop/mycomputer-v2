<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')
                ->unique('checkout_idempotency_records_cart_unique')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->unique('checkout_idempotency_records_order_unique')
                ->constrained()
                ->restrictOnDelete();
            $table->char('key_hash', 64)
                ->unique('checkout_idempotency_records_key_hash_unique');
            $table->char('request_hash', 64);
            $table->string('status', 20)
                ->index('checkout_idempotency_records_status_index');
            $table->timestamp('completed_at')
                ->nullable()
                ->index('checkout_idempotency_records_completed_at_index');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('checkout_idempotency_records')
            && DB::table('checkout_idempotency_records')->exists()
        ) {
            throw new RuntimeException(
                'Refusing to drop persistent checkout idempotency records while records exist.',
            );
        }

        Schema::dropIfExists('checkout_idempotency_records');
    }
};
