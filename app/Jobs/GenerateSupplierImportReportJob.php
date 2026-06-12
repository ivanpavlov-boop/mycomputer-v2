<?php

namespace App\Jobs;

use App\Models\SupplierImportRun;
use App\Services\Suppliers\SupplierImportReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateSupplierImportReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $supplierImportRunId)
    {
        $this->onQueue('imports');
    }

    public function handle(SupplierImportReportService $reports): void
    {
        $reports->generate(SupplierImportRun::query()->findOrFail($this->supplierImportRunId));
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
