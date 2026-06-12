<?php

namespace App\Jobs;

use App\Models\ConversionLog;
use App\Models\Order;
use App\Services\Marketing\ConversionTrackingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConversionTrackingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public ?int $orderId = null, public ?int $conversionLogId = null)
    {
        $this->onQueue('analytics');
    }

    public function viaQueue(): string
    {
        return 'analytics';
    }

    public function handle(ConversionTrackingService $tracking): void
    {
        if ($this->conversionLogId) {
            $tracking->dispatch(ConversionLog::query()->findOrFail($this->conversionLogId));

            return;
        }

        $tracking->purchase(Order::query()->findOrFail($this->orderId));
    }

    public function failed(Throwable $exception): void
    {
        if ($this->conversionLogId) {
            ConversionLog::query()->whereKey($this->conversionLogId)->update([
                'status' => 'failed',
                'response' => ['error' => $exception->getMessage()],
            ]);
        }
    }
}
