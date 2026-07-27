<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'checkout_confirmation_capabilities';

    private const UNIQUE_INDEX = 'checkout_confirmation_capabilities_order_id_unique';

    private const ORDER_INDEX = 'checkout_confirmation_capabilities_order_id_index';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index('order_id', self::ORDER_INDEX);
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        $hasDuplicates = DB::table(self::TABLE)
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Refusing to restore the unique confirmation Order constraint while duplicate capabilities exist.',
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique('order_id', self::UNIQUE_INDEX);
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::ORDER_INDEX);
        });
    }
};
