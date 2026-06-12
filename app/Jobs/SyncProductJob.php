<?php

namespace App\Jobs;

use App\Models\SupplierProduct;
use App\Services\Products\ProductSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public array $backoff = [30, 120, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(public int $supplierProductId, public ?string $strategy = null)
    {
        $this->onQueue('sync');
    }

    public function viaQueue(): string
    {
        return 'sync';
    }

    /**
     * Execute the job.
     */
    public function handle(ProductSyncService $syncService): void
    {
        $syncService->sync(
            SupplierProduct::query()->findOrFail($this->supplierProductId),
            $this->strategy,
        );
    }

    public function failed(Throwable $exception): void
    {
        SupplierProduct::query()->whereKey($this->supplierProductId)->update([
            'status' => 'sync_failed',
            'mapping_notes' => $exception->getMessage(),
        ]);
    }
}
