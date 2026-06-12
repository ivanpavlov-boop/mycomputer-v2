<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tracking_number')->nullable()->index();
            $table->string('label_path')->nullable();
            $table->foreignId('office_id')->nullable()->constrained('shipping_offices')->nullOnDelete();
            $table->string('delivery_type')->index();
            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->string('city');
            $table->string('postcode')->nullable();
            $table->string('address')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('status')->default('pending')->index();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipments');
    }
};
