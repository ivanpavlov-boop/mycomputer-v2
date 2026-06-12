<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Email\EmailMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPriceDropAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $productId)
    {
        $this->onQueue('emails');
    }

    public function handle(EmailMarketingService $emailMarketing): void
    {
        if ($product = Product::query()->find($this->productId)) {
            $emailMarketing->triggerPriceDrop($product);
        }
    }
}
