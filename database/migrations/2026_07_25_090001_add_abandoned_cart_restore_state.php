<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESTORED_AT_INDEX = 'abandoned_cart_records_restored_at_index';

    private const RESTORED_CART_UNIQUE = 'abandoned_cart_records_restored_cart_unique';

    private const RESTORED_CART_FOREIGN = 'abandoned_cart_records_restored_cart_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('abandoned_cart_records')) {
            throw new RuntimeException('The abandoned_cart_records table is required.');
        }

        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            $table->timestamp('restored_at')->nullable()->after('emails_sent');
            $table->unsignedBigInteger('restored_cart_id')->nullable()->after('restored_at');
            $table->index('restored_at', self::RESTORED_AT_INDEX);
            $table->unique('restored_cart_id', self::RESTORED_CART_UNIQUE);
            $table->foreign('restored_cart_id', self::RESTORED_CART_FOREIGN)
                ->references('id')
                ->on('carts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $hasRestoreAudit = DB::table('abandoned_cart_records')
            ->whereNotNull('restored_at')
            ->orWhereNotNull('restored_cart_id')
            ->orWhere('status', 'restored')
            ->exists();

        if ($hasRestoreAudit) {
            throw new RuntimeException('Restore audit data exists; the recovery-state migration cannot be rolled back.');
        }

        Schema::table('abandoned_cart_records', function (Blueprint $table): void {
            $table->dropForeign(self::RESTORED_CART_FOREIGN);
            $table->dropUnique(self::RESTORED_CART_UNIQUE);
            $table->dropIndex(self::RESTORED_AT_INDEX);
            $table->dropColumn(['restored_cart_id', 'restored_at']);
        });
    }
};
