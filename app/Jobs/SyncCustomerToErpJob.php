<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\ErpCustomerMapping;
use App\Models\ErpSyncJob;
use App\Models\User;
use App\Services\Erp\ErpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncCustomerToErpJob implements ShouldQueue
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
        $customer = $job->payload['model'] === 'user'
            ? User::query()->findOrFail($job->entity_id)
            : Customer::query()->findOrFail($job->entity_id);

        $response = $erp->provider($provider)->pushCustomer($customer);

        $status = match (true) {
            $response['manual'] ?? false => 'pending',
            ($response['success'] ?? true) === false => 'failed',
            default => 'success',
        };

        $job->update([
            'provider_id' => $provider?->id,
            'status' => $status,
            'response' => $response,
            'external_id' => $response['external_id'] ?? null,
            'last_error' => $status === 'failed' ? ($response['message'] ?? 'ERP provider returned an unsuccessful response.') : null,
            'synced_at' => $status === 'success' ? now() : null,
        ]);

        if ($status === 'success') {
            ErpCustomerMapping::query()->updateOrCreate(
                [
                    'provider_id' => $provider?->id,
                    $customer instanceof User ? 'user_id' : 'customer_id' => $customer->id,
                ],
                [
                    'external_customer_id' => $response['external_id'] ?? null,
                    'external_company_id' => $response['external_company_id'] ?? null,
                    'sync_enabled' => true,
                    'last_synced_at' => now(),
                ],
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        ErpSyncJob::query()->whereKey($this->syncJobId)->update([
            'status' => 'failed',
            'last_error' => $exception->getMessage(),
        ]);
    }
}
