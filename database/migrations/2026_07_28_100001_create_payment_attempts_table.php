<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_transaction_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->char('request_hash', 64);
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20)->index();
            $table->string('authorization_type', 30)->index();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_reference')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();

            $table->unique(
                ['order_id', 'attempt_number'],
                'payment_attempts_order_attempt_unique',
            );
            $table->unique(
                ['payment_provider_id', 'provider_reference'],
                'payment_attempts_provider_reference_unique',
            );
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('payment_attempts')
            && DB::table('payment_attempts')->exists()
        ) {
            throw new RuntimeException(
                'Refusing to drop payment attempts while records exist.',
            );
        }

        Schema::dropIfExists('payment_attempts');
    }
};
