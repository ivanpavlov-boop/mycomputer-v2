<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HASH_UNIQUE = 'abandoned_cart_records_recovery_capability_hash_unique';

    private const EXPIRES_INDEX = 'abandoned_cart_records_recovery_capability_expires_at_index';

    public function up(): void
    {
        if (! Schema::hasTable('abandoned_cart_records')) {
            throw new RuntimeException('The abandoned_cart_records table is required.');
        }

        DB::table('abandoned_cart_records')->update([
            'recovery_token_expires_at' => null,
        ]);

        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            $table->char('recovery_capability_hash', 64)
                ->nullable()
                ->after('last_cart_activity_at');
            $table->timestamp('recovery_capability_expires_at')
                ->nullable()
                ->after('recovery_capability_hash');
            $table->unique('recovery_capability_hash', self::HASH_UNIQUE);
            $table->index('recovery_capability_expires_at', self::EXPIRES_INDEX);
        });

        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            $table->dropUnique('abandoned_cart_records_recovery_token_unique');
            $table->dropIndex('abandoned_cart_records_recovery_token_expires_at_index');
        });

        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            $table->dropColumn([
                'recovery_token',
                'recovery_token_expires_at',
            ]);
        });
    }

    public function down(): void
    {
        if (DB::table('abandoned_cart_records')->exists()) {
            throw new RuntimeException(
                'Refusing to restore plaintext recovery columns while abandoned Cart records exist.',
            );
        }

        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            $table->string('recovery_token', 80)->nullable()->unique();
            $table->timestamp('recovery_token_expires_at')->nullable()->index();
        });

        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            $table->dropUnique(self::HASH_UNIQUE);
            $table->dropIndex(self::EXPIRES_INDEX);
            $table->dropColumn([
                'recovery_capability_hash',
                'recovery_capability_expires_at',
            ]);
        });
    }
};
