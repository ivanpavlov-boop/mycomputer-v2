<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Models\Order;
use App\Services\Erp\ErpService;

class QueueOrderCancellationErpSync
{
    public function handle(OrderCancelled $event): void
    {
        $erp = app(ErpService::class);
        $provider = $erp->activeProvider();

        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $erp->createSyncJob('push', 'order', $order->id, [
            'order_number' => $order->order_number,
            'status' => 'cancelled',
        ], $provider);
    }
}
