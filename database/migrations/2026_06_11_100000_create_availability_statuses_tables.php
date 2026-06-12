<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color')->default('green');
            $table->string('icon')->nullable();
            $table->string('badge_style')->nullable();
            $table->boolean('allow_purchase')->default(false)->index();
            $table->boolean('show_stock_quantity')->default(false);
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('availability_status_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->index();
            $table->string('source_code')->nullable()->index();
            $table->string('external_status')->index();
            $table->string('external_status_label')->nullable();
            $table->foreignId('availability_status_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['source_type', 'source_code', 'external_status', 'is_active'], 'availability_mapping_lookup');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('availability_status_id')->nullable()->after('stock_status')->constrained('availability_statuses')->nullOnDelete();
            $table->string('product_status')->default('draft')->after('availability_status_id')->index();
            $table->string('availability_message')->nullable()->after('product_status');
            $table->date('expected_date')->nullable()->after('availability_message');
            $table->unsignedSmallInteger('supplier_lead_time_days')->nullable()->after('expected_date');
            $table->boolean('manual_override')->default(false)->after('supplier_lead_time_days')->index();
            $table->string('external_availability_status')->nullable()->after('manual_override')->index();
            $table->string('external_availability_label')->nullable()->after('external_availability_status');

            $table->index(['availability_status_id', 'active'], 'products_availability_active_idx');
            $table->index(['product_status', 'active'], 'products_product_status_active_idx');
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->string('external_availability_status')->nullable()->after('quantity')->index();
            $table->string('external_availability_label')->nullable()->after('external_availability_status');
            $table->foreignId('availability_status_id')->nullable()->after('external_availability_label')->constrained('availability_statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('availability_status_id');
            $table->dropColumn(['external_availability_status', 'external_availability_label']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_availability_active_idx');
            $table->dropIndex('products_product_status_active_idx');
            $table->dropConstrainedForeignId('availability_status_id');
            $table->dropColumn([
                'product_status',
                'availability_message',
                'expected_date',
                'supplier_lead_time_days',
                'manual_override',
                'external_availability_status',
                'external_availability_label',
            ]);
        });

        Schema::dropIfExists('availability_status_mappings');
        Schema::dropIfExists('availability_statuses');
    }
};
