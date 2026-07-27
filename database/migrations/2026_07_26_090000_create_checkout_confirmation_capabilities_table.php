<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_confirmation_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('checkout_confirmation_capabilities')
            && DB::table('checkout_confirmation_capabilities')->exists()
        ) {
            throw new RuntimeException(
                'Refusing to drop checkout confirmation capabilities while records exist.',
            );
        }

        Schema::dropIfExists('checkout_confirmation_capabilities');
    }
};
