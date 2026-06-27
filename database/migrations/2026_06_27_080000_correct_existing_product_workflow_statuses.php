<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'workflow_status')) {
            return;
        }

        $now = now()->toDateTimeString();

        $query = DB::table('products')
            ->where('active', true)
            ->where(function ($query): void {
                $query
                    ->whereNull('product_status')
                    ->orWhereNotIn('product_status', ['draft', 'hidden', 'inactive']);
            });

        if (Schema::hasColumn('products', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $query->update([
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'published_at' => DB::raw("COALESCE(published_at, updated_at, created_at, '{$now}')"),
        ]);
    }

    public function down(): void
    {
        // Intentionally left blank: rolling back this safety correction must not re-hide catalog products.
    }
};
