<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Jobs\SyncOrderToErpJob;
use App\Models\Order;
use App\Services\Erp\ErpService;

class QueueOrderErpSync
{
    public function handle(OrderCreated $event): void
    {
        $erp = app(ErpService::class);
        $provider = $erp->activeProvider();

        $order = Order::query()->with(['items', 'bundleItems', 'customer', 'user'])->find($event->orderId);

        if (! $order) {
            return;
        }

        $syncJob = $erp->createSyncJob('push', 'order', $order->id, $erp->orderPayload($order), $provider);

        if ($provider) {
            SyncOrderToErpJob::dispatch($syncJob->id);
        }
    }
}
