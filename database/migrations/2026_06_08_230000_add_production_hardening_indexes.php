<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['active', 'published_at', 'category_id', 'price'], 'products_public_category_price_idx');
            $table->index(['active', 'published_at', 'brand_id', 'price'], 'products_public_brand_price_idx');
            $table->index(['active', 'stock_status', 'price'], 'products_stock_price_idx');
            $table->index(['featured', 'active', 'published_at'], 'products_featured_public_idx');
        });

        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->index(['supplier_id', 'status', 'synced_at'], 'supplier_products_supplier_status_synced_idx');
            $table->index(['payload_hash', 'supplier_id'], 'supplier_products_hash_supplier_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'orders_user_created_idx');
            $table->index(['status', 'created_at'], 'orders_status_created_idx');
            $table->index(['customer_email', 'created_at'], 'orders_email_created_idx');
        });

        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->index(['product_id', 'status', 'created_at'], 'reviews_product_status_created_idx');
        });

        Schema::table('marketing_events', function (Blueprint $table): void {
            $table->index(['source', 'event_name', 'created_at'], 'marketing_source_event_created_idx');
        });

        Schema::table('conversion_logs', function (Blueprint $table): void {
            $table->index(['provider', 'status', 'created_at'], 'conversion_provider_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('conversion_logs', fn (Blueprint $table) => $table->dropIndex('conversion_provider_status_created_idx'));
        Schema::table('marketing_events', fn (Blueprint $table) => $table->dropIndex('marketing_source_event_created_idx'));
        Schema::table('product_reviews', fn (Blueprint $table) => $table->dropIndex('reviews_product_status_created_idx'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_user_created_idx');
            $table->dropIndex('orders_status_created_idx');
            $table->dropIndex('orders_email_created_idx');
        });
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropIndex('supplier_products_supplier_status_synced_idx');
            $table->dropIndex('supplier_products_hash_supplier_idx');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_public_category_price_idx');
            $table->dropIndex('products_public_brand_price_idx');
            $table->dropIndex('products_stock_price_idx');
            $table->dropIndex('products_featured_public_idx');
        });
    }
};
