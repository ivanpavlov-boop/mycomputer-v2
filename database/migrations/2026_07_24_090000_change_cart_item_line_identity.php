<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_INDEX = 'cart_items_cart_id_product_id_unique';

    private const NEW_INDEX = 'cart_items_cart_product_gift_unique';

    public function up(): void
    {
        if (! Schema::hasTable('cart_items') || ! Schema::hasColumn('cart_items', 'is_gift')) {
            throw new RuntimeException('Cart item gift identity requires the cart_items.is_gift column.');
        }

        $hasDuplicateIdentity = DB::table('cart_items')
            ->select(['cart_id', 'product_id', 'is_gift'])
            ->groupBy(['cart_id', 'product_id', 'is_gift'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateIdentity) {
            throw new RuntimeException('Cannot add Cart item gift identity while duplicate line identities exist.');
        }

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unique(['cart_id', 'product_id', 'is_gift'], self::NEW_INDEX);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(self::OLD_INDEX);
        });
    }

    public function down(): void
    {
        $hasPaidGiftCoexistence = DB::table('cart_items')
            ->select(['cart_id', 'product_id'])
            ->groupBy(['cart_id', 'product_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasPaidGiftCoexistence) {
            throw new RuntimeException(
                'Cannot restore the legacy Cart item identity while paid and gift lines coexist.',
            );
        }

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unique(['cart_id', 'product_id'], self::OLD_INDEX);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(self::NEW_INDEX);
        });
    }
};
