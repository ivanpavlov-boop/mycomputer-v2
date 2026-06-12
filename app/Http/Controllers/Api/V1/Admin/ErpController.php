<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCustomerToErpJob;
use App\Jobs\SyncOrderToErpJob;
use App\Models\Customer;
use App\Models\ErpProvider;
use App\Models\ErpSyncJob;
use App\Models\Order;
use App\Services\Erp\ErpService;
use Illuminate\Http\JsonResponse;

class ErpController extends Controller
{
    public function status(ErpService $erp): JsonResponse
    {
        abort_unless(request()->user()?->can('view erp logs'), 403);

        $provider = $erp->activeProvider();

        return response()->json([
            'data' => [
                'active_provider' => $provider ? [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'code' => $provider->code,
                    'status' => $provider->status,
                ] : null,
                'pending_jobs' => ErpSyncJob::query()->where('status', 'pending')->count(),
                'failed_jobs' => ErpSyncJob::query()->where('status', 'failed')->count(),
                'successful_today' => ErpSyncJob::query()->where('status', 'success')->whereDate('synced_at', today())->count(),
                'providers_count' => ErpProvider::query()->count(),
            ],
        ]);
    }

    public function syncOrder(Order $order, ErpService $erp): JsonResponse
    {
        abort_unless(request()->user()?->can('manage erp'), 403);

        $syncJob = $erp->createSyncJob('push', 'order', $order->id, $erp->orderPayload($order));
        SyncOrderToErpJob::dispatch($syncJob->id);

        return response()->json(['data' => ['sync_job_id' => $syncJob->id]], 202);
    }

    public function syncCustomer(Customer $customer, ErpService $erp): JsonResponse
    {
        abort_unless(request()->user()?->can('manage erp'), 403);

        $syncJob = $erp->createSyncJob('push', 'customer', $customer->id, $erp->customerPayload($customer) + ['model' => 'customer']);
        SyncCustomerToErpJob::dispatch($syncJob->id);

        return response()->json(['data' => ['sync_job_id' => $syncJob->id]], 202);
    }
}
