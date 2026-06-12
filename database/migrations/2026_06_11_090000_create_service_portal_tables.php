<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('b2b_company_id')->nullable()->constrained('b2b_companies')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ticket_type')->index();
            $table->string('status')->default('new')->index();
            $table->string('priority')->default('normal')->index();
            $table->string('subject');
            $table->text('description');
            $table->string('serial_number')->nullable()->index();
            $table->date('purchased_at')->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('resolution')->nullable();
            $table->text('work_performed')->nullable();
            $table->json('parts_used')->nullable();
            $table->date('repair_date')->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->date('refund_date')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['b2b_company_id', 'status']);
            $table->index(['ticket_type', 'status']);
        });

        Schema::create('service_ticket_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained('service_tickets')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_type');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('service_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained('service_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->boolean('internal_note')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_messages');
        Schema::dropIfExists('service_ticket_files');
        Schema::dropIfExists('service_tickets');
    }
};
