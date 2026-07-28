<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leasing_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status')->index();
            $table->unsignedSmallInteger('requested_term_months');
            $table->decimal('requested_down_payment', 12, 2);
            $table->string('currency', 3);
            $table->string('preferred_contact_method');
            $table->string('preferred_contact_time')->nullable();
            $table->text('customer_note')->nullable();
            $table->timestamp('contact_consent_at');
            $table->string('consent_version');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leasing_applications');
    }
};
