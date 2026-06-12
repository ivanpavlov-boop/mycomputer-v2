<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Imports\XmlImportEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessXmlSupplierFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1200;

    public array $backoff = [120, 600, 1800];

    /**
     * Create a new job instance.
     */
    public function __construct(public int $importJobId)
    {
        $this->onQueue('imports');
    }

    public function viaQueue(): string
    {
        return 'imports';
    }

    /**
     * Execute the job.
     */
    public function handle(XmlImportEngine $engine): void
    {
        $engine->import(ImportJob::query()->findOrFail($this->importJobId));
    }

    public function failed(Throwable $exception): void
    {
        ImportJob::query()->whereKey($this->importJobId)->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $exception->getMessage(),
        ]);
    }
}
