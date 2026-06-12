<?php

namespace App\Services\Suppliers;

use App\Models\SupplierImportRun;

class SupplierImportReportService
{
    public function generate(SupplierImportRun $run): SupplierImportRun
    {
        $run->loadMissing('supplier', 'feed');

        $report = [
            'supplier' => $run->supplier?->company_name,
            'trigger' => $run->trigger_type,
            'status' => $run->status,
            'duration_seconds' => $run->duration_seconds,
            'products_seen' => $run->products_seen,
            'products_created' => $run->products_created,
            'products_updated' => $run->products_updated,
            'products_failed' => $run->products_failed,
            'products_skipped' => $run->products_skipped,
            'products_out_of_stock' => $run->products_out_of_stock,
            'products_needs_review' => $run->products_needs_review,
            'attributes_mapped' => $run->attributes_mapped,
            'attributes_unmapped' => $run->attributes_unmapped,
            'availability_mapped' => $run->availability_mapped,
            'availability_unmapped' => $run->availability_unmapped,
            'warnings' => $run->warnings ?? [],
            'errors' => $run->errors ?? [],
        ];

        $run->update(['report' => $report]);

        return $run->fresh();
    }
}
