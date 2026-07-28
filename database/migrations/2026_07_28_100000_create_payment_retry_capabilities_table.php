<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_retry_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('payment_retry_capabilities')
            && DB::table('payment_retry_capabilities')->exists()
        ) {
            throw new RuntimeException(
                'Refusing to drop payment retry capabilities while records exist.',
            );
        }

        Schema::dropIfExists('payment_retry_capabilities');
    }
};
