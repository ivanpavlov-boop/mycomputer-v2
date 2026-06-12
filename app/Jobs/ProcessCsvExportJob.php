<?php

namespace App\Jobs;

use App\Models\CsvExportJob;
use App\Services\Csv\CsvExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCsvExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $csvExportJobId)
    {
        $this->onQueue('exports');
    }

    public function viaQueue(): string
    {
        return 'exports';
    }

    public function handle(CsvExportService $csvExportService): void
    {
        $csvExportService->process(CsvExportJob::query()->findOrFail($this->csvExportJobId));
    }

    public function failed(Throwable $exception): void
    {
        CsvExportJob::query()->whereKey($this->csvExportJobId)->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $exception->getMessage(),
        ]);
    }
}
