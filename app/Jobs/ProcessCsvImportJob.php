<?php

namespace App\Jobs;

use App\Models\CsvImportJob;
use App\Services\Csv\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCsvImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $csvImportJobId)
    {
        $this->onQueue('imports');
    }

    public function viaQueue(): string
    {
        return 'imports';
    }

    public function handle(CsvImportService $csvImportService): void
    {
        $csvImportService->process(CsvImportJob::query()->findOrFail($this->csvImportJobId));
    }

    public function failed(Throwable $exception): void
    {
        CsvImportJob::query()->whereKey($this->csvImportJobId)->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $exception->getMessage(),
        ]);
    }
}
