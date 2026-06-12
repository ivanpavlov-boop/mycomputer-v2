<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_email')->index();
            $table->string('customer_phone');
            $table->string('customer_name');
            $table->string('company_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->text('billing_address');
            $table->text('shipping_address');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('shipping_price', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->string('payment_method')->index();
            $table->string('payment_status')->default('pending')->index();
            $table->string('shipping_method')->index();
            $table->string('shipping_status')->default('pending')->index();
            $table->string('status')->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
