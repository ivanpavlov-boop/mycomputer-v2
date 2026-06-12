<?php

namespace App\Jobs;

use App\Models\SupplierImportRun;
use App\Services\Suppliers\SupplierImportOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunSupplierImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public array $backoff = [120, 600, 1800];

    public function __construct(public int $supplierImportRunId, public bool $force = false)
    {
        $this->onQueue('imports');
    }

    public function handle(SupplierImportOrchestrator $orchestrator): void
    {
        $orchestrator->execute(SupplierImportRun::query()->findOrFail($this->supplierImportRunId), $this->force);
    }

    public function failed(Throwable $exception): void
    {
        SupplierImportRun::query()->whereKey($this->supplierImportRunId)->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_count' => 1,
            'errors' => [$exception->getMessage()],
        ]);
    }
}
