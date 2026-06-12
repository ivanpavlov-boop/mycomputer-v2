<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('vat_number')->index();
            $table->string('company_number')->nullable();
            $table->string('mol')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('status')->default('inactive')->index();
            $table->string('approval_status')->default('pending')->index();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('b2b_company_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('b2b_company_id')->constrained('b2b_companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('buyer')->index();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['b2b_company_id', 'user_id']);
        });

        Schema::create('quote_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('b2b_company_id')->nullable()->constrained('b2b_companies')->nullOnDelete();
            $table->string('quote_number')->unique();
            $table->string('customer_name');
            $table->string('customer_email')->index();
            $table->string('customer_phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('source')->default('b2b_portal')->index();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('discount_total', 12, 2)->nullable();
            $table->decimal('grand_total', 12, 2)->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quote_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('quantity');
            $table->decimal('requested_price', 12, 2)->nullable();
            $table->decimal('offered_price', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_request_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender_type')->index();
            $table->text('message');
            $table->boolean('is_internal')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('quote_request_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('b2b_company_id')->nullable()->after('user_id')->constrained('b2b_companies')->nullOnDelete();
            $table->foreignId('quote_request_id')->nullable()->after('b2b_company_id')->constrained('quote_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quote_request_id');
            $table->dropConstrainedForeignId('b2b_company_id');
        });

        Schema::dropIfExists('quote_request_files');
        Schema::dropIfExists('quote_request_messages');
        Schema::dropIfExists('quote_request_items');
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('b2b_company_users');
        Schema::dropIfExists('b2b_companies');
    }
};
