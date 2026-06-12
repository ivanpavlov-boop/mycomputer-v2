<?php

namespace App\Jobs;

use App\Models\ErpSyncJob;
use App\Models\Order;
use App\Services\Erp\ErpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncPaymentToErpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

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
        $order = Order::query()->with('paymentTransactions')->findOrFail($job->entity_id);
        $response = $erp->provider($provider)->pushPayment($order);
        $status = match (true) {
            $response['manual'] ?? false => 'pending',
            ($response['success'] ?? true) === false => 'failed',
            default => 'success',
        };

        $job->update([
            'provider_id' => $provider?->id,
            'status' => $status,
            'payload' => $erp->paymentPayload($order),
            'response' => $response,
            'external_id' => $response['external_id'] ?? null,
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
