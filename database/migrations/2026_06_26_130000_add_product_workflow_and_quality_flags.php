<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('workflow_status')->default(Product::WORKFLOW_PUBLISHED)->after('product_status')->index();
            $table->foreignId('created_by')->nullable()->after('workflow_status')->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('submitted_by')->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->after('published_by')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->after('returned_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('assigned_to');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->timestamp('returned_at')->nullable()->after('approved_at');
            $table->text('review_notes')->nullable()->after('returned_at');
        });

        Product::query()
            ->where('active', true)
            ->whereNotNull('published_at')
            ->update(['workflow_status' => Product::WORKFLOW_PUBLISHED]);

        Product::query()
            ->where(function ($query): void {
                $query->where('active', false)->orWhereNull('published_at');
            })
            ->update(['workflow_status' => Product::WORKFLOW_DRAFT]);

        Schema::create('product_quality_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('label_bg');
            $table->string('label_en')->nullable();
            $table->text('description_bg')->nullable();
            $table->text('description_en')->nullable();
            $table->string('severity')->default('medium')->index();
            $table->string('responsible_role')->nullable()->index();
            $table->string('type')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_quality_flag_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_quality_flag_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->text('note')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'product_quality_flag_id'], 'pq_flag_assignments_product_flag_unique');
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_quality_flag_assignments');
        Schema::dropIfExists('product_quality_flags');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('published_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn([
                'workflow_status',
                'submitted_at',
                'approved_at',
                'returned_at',
                'review_notes',
            ]);
        });
    }
};
