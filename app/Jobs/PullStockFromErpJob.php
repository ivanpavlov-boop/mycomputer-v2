<?php

namespace App\Jobs;

use App\Models\ErpSyncJob;
use App\Services\Erp\ErpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PullStockFromErpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $syncJobId)
    {
        $this->onQueue('erp');
    }

    public function viaQueue(): string
    {
        return 'erp';
    }

    public function handle(ErpService $erp): void
    {
        $job = ErpSyncJob::query()->findOrFail($this->syncJobId);
        $job->increment('attempts');
        $job->update(['status' => 'processing']);

        $provider = $job->provider ?: $erp->activeProvider();
        $response = $erp->provider($provider)->pullStock();
        $status = match (true) {
            ($response['manual'] ?? false) || ($response['status'] ?? null) === 'skipped' => 'skipped',
            ($response['success'] ?? true) === false => 'failed',
            default => 'success',
        };

        $job->update([
            'provider_id' => $provider?->id,
            'status' => $status,
            'response' => $response,
            'last_error' => $status === 'failed' ? ($response['message'] ?? 'ERP provider returned an unsuccessful response.') : null,
            'synced_at' => $status === 'success' ? now() : null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        ErpSyncJob::query()->whereKey($this->syncJobId)->update([
            'status' => 'failed',
            'last_error' => $exception->getMessage(),
        ]);
    }
}
