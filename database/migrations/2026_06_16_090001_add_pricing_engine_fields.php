<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('msrp_strategy')->default('margin_only')->after('sync_strategy');
            $table->string('vat_mode')->default('price_excludes_vat')->after('msrp_strategy');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('vat_mode');
        });

        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->decimal('supplier_price_raw', 12, 2)->nullable()->after('price');
            $table->decimal('recommended_price', 12, 2)->nullable()->after('supplier_price_raw');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('supplier_price_raw', 12, 2)->nullable()->after('purchase_price');
            $table->decimal('recommended_price', 12, 2)->nullable()->after('supplier_price_raw');
            $table->decimal('final_selling_price', 12, 2)->nullable()->after('recommended_price');
            $table->string('source')->default('manual')->after('final_selling_price')->index();
            $table->boolean('apply_pricing_rules')->default(false)->after('source')->index();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE supplier_products MODIFY currency VARCHAR(3) NOT NULL DEFAULT 'EUR'");
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'supplier_price_raw',
                'recommended_price',
                'final_selling_price',
                'source',
                'apply_pricing_rules',
            ]);
        });

        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropColumn(['supplier_price_raw', 'recommended_price']);
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn(['msrp_strategy', 'vat_mode', 'vat_rate']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE supplier_products MODIFY currency VARCHAR(3) NOT NULL DEFAULT 'BGN'");
        }
    }
};
