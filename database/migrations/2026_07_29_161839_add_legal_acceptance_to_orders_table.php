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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('legal_accepted_at')->nullable();
            $table->string('terms_version', 64)->nullable();
            $table->string('privacy_version', 64)->nullable();
            $table->string('legal_acceptance_locale', 10)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'legal_accepted_at',
                'terms_version',
                'privacy_version',
                'legal_acceptance_locale',
            ]);
        });
    }
};
