<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierImportRun;

class SupplierImportSafetyService
{
    public function evaluate(Supplier $supplier, SupplierImportRun $run): array
    {
        $warnings = [];
        $errors = [];
        $blockSync = false;

        if ($run->products_seen <= 0) {
            $errors[] = 'Empty feed protection triggered: feed returned zero products.';
            $blockSync = true;
        }

        if ($run->products_seen < (int) $supplier->minimum_product_count) {
            $errors[] = "Minimum product count protection triggered: {$run->products_seen} products seen.";
            $blockSync = true;
        }

        $previous = SupplierImportRun::query()
            ->where('supplier_id', $supplier->id)
            ->where('id', '!=', $run->id)
            ->whereIn('status', ['completed', 'completed_with_warnings'])
            ->where('products_seen', '>', 0)
            ->latest('finished_at')
            ->first();

        if ($previous && $previous->products_seen > 0) {
            $dropPercent = (($previous->products_seen - $run->products_seen) / $previous->products_seen) * 100;

            if ($dropPercent > (float) $supplier->maximum_product_drop_percent) {
                $warnings[] = sprintf(
                    'Mass product loss protection triggered: product count dropped %.2f%% from %d to %d.',
                    $dropPercent,
                    $previous->products_seen,
                    $run->products_seen,
                );
                $blockSync = ! $supplier->allow_destructive_sync;
            }
        }

        return [
            'warnings' => $warnings,
            'errors' => $errors,
            'block_sync' => $blockSync,
        ];
    }
}
