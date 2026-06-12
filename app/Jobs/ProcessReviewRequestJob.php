<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Email\EmailMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessReviewRequestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $orderId)
    {
        $this->onQueue('emails');
    }

    public function handle(EmailMarketingService $emailMarketing): void
    {
        $order = Order::query()->with('items')->find($this->orderId);
        if ($order) {
            $emailMarketing->queue($order->customer_email, 'review_request', ['order' => $order]);
        }
    }
}
