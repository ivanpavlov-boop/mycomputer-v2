<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['source', 'workflow_status', 'published_at', 'active', 'product_status'] as $column) {
            if (! Schema::hasColumn('products', $column)) {
                return;
            }
        }

        $query = DB::table('products')
            ->where('source', Product::SOURCE_SUPPLIER_IMPORT)
            ->where('workflow_status', Product::WORKFLOW_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('active', false)
            ->where('product_status', 'draft');

        if (Schema::hasColumn('products', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $updates = [
            'active' => true,
            'product_status' => 'active',
        ];

        if (Schema::hasColumn('products', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        $query->update($updates);
    }

    public function down(): void
    {
        // Intentionally blank: rolling back must not re-hide published supplier catalog products.
    }
};
