<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_provider_id')->constrained()->cascadeOnDelete();
            $table->string('office_id');
            $table->string('name');
            $table->string('city')->index();
            $table->string('postcode')->nullable()->index();
            $table->string('address');
            $table->string('phone')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('raw_data')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['shipping_provider_id', 'office_id']);
            $table->index(['city', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_offices');
    }
};
