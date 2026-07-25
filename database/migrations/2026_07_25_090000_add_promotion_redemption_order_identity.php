<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'promotion_redemptions_promotion_order_unique';

    public function up(): void
    {
        if (! Schema::hasTable('promotion_redemptions')) {
            throw new RuntimeException('The promotion_redemptions table is required.');
        }

        $hasDuplicate = DB::table('promotion_redemptions')
            ->select(['promotion_id', 'order_id'])
            ->whereNotNull('order_id')
            ->groupBy('promotion_id', 'order_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicate) {
            throw new RuntimeException(
                'Duplicate non-null promotion/order redemptions must be resolved before adding the unique identity.',
            );
        }

        Schema::table('promotion_redemptions', function (Blueprint $table): void {
            $table->unique(['promotion_id', 'order_id'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('promotion_redemptions', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }
};
