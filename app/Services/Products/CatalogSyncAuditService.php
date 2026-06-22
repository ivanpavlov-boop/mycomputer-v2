<?php

namespace App\Services\Products;

use App\Models\CatalogSyncBatch;
use App\Models\CatalogSyncLog;
use App\Models\Product;
use App\Models\SupplierProduct;
use Illuminate\Support\Str;
use Throwable;

class CatalogSyncAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function startBatch(
        string $mode,
        ?int $userId,
        ?int $supplierId,
        int $selectedCount,
        array $metadata = [],
    ): CatalogSyncBatch {
        return CatalogSyncBatch::query()->create([
            'batch_uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'supplier_id' => $supplierId,
            'mode' => $mode,
            'status' => CatalogSyncBatch::STATUS_RUNNING,
            'selected_count' => $selectedCount,
            'started_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function recordSuccess(
        CatalogSyncBatch $batch,
        ?SupplierProduct $supplierProduct,
        ?Product $product,
        string $action,
        ?string $reason = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = [],
    ): CatalogSyncLog {
        return $this->recordRow(
            $batch,
            $supplierProduct,
            $product,
            $action,
            CatalogSyncLog::STATUS_SUCCESS,
            $reason,
            $oldValues,
            $newValues,
            null,
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordSkipped(
        CatalogSyncBatch $batch,
        ?SupplierProduct $supplierProduct,
        ?Product $product,
        string $action,
        string $reason,
        array $metadata = [],
    ): CatalogSyncLog {
        return $this->recordRow(
            $batch,
            $supplierProduct,
            $product,
            $action,
            CatalogSyncLog::STATUS_SKIPPED,
            $reason,
            null,
            null,
            null,
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordFailure(
        CatalogSyncBatch $batch,
        ?SupplierProduct $supplierProduct,
        ?Product $product,
        string $action,
        Throwable|string $error,
        ?string $reason = null,
        array $metadata = [],
    ): CatalogSyncLog {
        return $this->recordRow(
            $batch,
            $supplierProduct,
            $product,
            $action,
            CatalogSyncLog::STATUS_FAILED,
            $reason,
            null,
            null,
            $error instanceof Throwable ? $error->getMessage() : $error,
            $metadata,
        );
    }

    public function finishBatch(CatalogSyncBatch $batch): CatalogSyncBatch
    {
        $createdCount = $batch->logs()
            ->where('action', CatalogSyncLog::ACTION_CREATE)
            ->where('status', CatalogSyncLog::STATUS_SUCCESS)
            ->count();
        $updatedCount = $batch->logs()
            ->where('action', CatalogSyncLog::ACTION_UPDATE)
            ->where('status', CatalogSyncLog::STATUS_SUCCESS)
            ->count();
        $skippedCount = $batch->logs()->where('status', CatalogSyncLog::STATUS_SKIPPED)->count();
        $failedCount = $batch->logs()->where('status', CatalogSyncLog::STATUS_FAILED)->count();

        $batch->update([
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'status' => $this->finishedStatus($createdCount, $updatedCount, $skippedCount, $failedCount),
            'completed_at' => now(),
        ]);

        return $batch->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    protected function recordRow(
        CatalogSyncBatch $batch,
        ?SupplierProduct $supplierProduct,
        ?Product $product,
        string $action,
        string $status,
        ?string $reason,
        ?array $oldValues,
        ?array $newValues,
        ?string $errorMessage,
        array $metadata,
    ): CatalogSyncLog {
        return CatalogSyncLog::query()->create([
            'catalog_sync_batch_id' => $batch->id,
            'supplier_id' => $supplierProduct?->supplier_id ?? $batch->supplier_id,
            'supplier_product_id' => $supplierProduct?->id,
            'product_id' => $product?->id,
            'action' => $action,
            'status' => $status,
            'reason' => $reason,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
        ]);
    }

    protected function finishedStatus(int $createdCount, int $updatedCount, int $skippedCount, int $failedCount): string
    {
        if ($failedCount > 0) {
            return ($createdCount + $updatedCount + $skippedCount) > 0
                ? CatalogSyncBatch::STATUS_PARTIAL
                : CatalogSyncBatch::STATUS_FAILED;
        }

        return CatalogSyncBatch::STATUS_COMPLETED;
    }
}
