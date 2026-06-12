<?php

namespace App\Listeners;

use App\Events\OrderPaymentStatusChanged;
use App\Jobs\SyncPaymentToErpJob;
use App\Models\Order;
use App\Services\Erp\ErpService;

class QueuePaymentErpSync
{
    public function handle(OrderPaymentStatusChanged $event): void
    {
        $erp = app(ErpService::class);
        $provider = $erp->activeProvider();

        $order = Order::query()->with('paymentTransactions')->find($event->orderId);

        if (! $order) {
            return;
        }

        $syncJob = $erp->createSyncJob('push', 'payment', $order->id, $erp->paymentPayload($order), $provider);

        if ($provider) {
            SyncPaymentToErpJob::dispatch($syncJob->id);
        }
    }
}
