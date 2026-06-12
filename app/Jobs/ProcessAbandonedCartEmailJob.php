<?php

namespace App\Jobs;

use App\Models\AbandonedCartRecord;
use App\Services\Email\EmailMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAbandonedCartEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $backoff = 60;

    public function __construct(public int $recordId)
    {
        $this->onQueue('emails');
    }

    public function handle(EmailMarketingService $emailMarketing): void
    {
        $record = AbandonedCartRecord::query()->find($this->recordId);

        if ($record) {
            $emailMarketing->processAbandonedCart($record);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Abandoned cart recovery email job failed.', [
            'record_id' => $this->recordId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
