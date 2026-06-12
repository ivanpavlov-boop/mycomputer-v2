<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supplier_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('feed_name');
            $table->string('feed_type')->default('xml')->index();
            $table->string('feed_url');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('update_interval')->default('manual')->index();
            $table->json('mapping')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->index(['supplier_id', 'feed_type']);
            $table->index(['status', 'update_interval']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_feeds');
    }
};
