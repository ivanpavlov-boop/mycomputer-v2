<?php

namespace App\Jobs;

use App\Models\AbandonedCartRecord;
use App\Models\Cart;
use App\Services\Email\EmailMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAbandonedCartJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $backoff = 60;

    public function __construct(public ?int $cartId = null, public ?int $recordId = null)
    {
        $this->onQueue('emails');
    }

    public function handle(EmailMarketingService $emailMarketing): void
    {
        if ($this->recordId) {
            $record = AbandonedCartRecord::query()->find($this->recordId);
            if ($record) {
                $emailMarketing->processAbandonedCart($record);
            }

            return;
        }

        if ($this->cartId && ($cart = Cart::query()->with('items.product', 'user')->find($this->cartId))) {
            $emailMarketing->recordAbandonedCart($cart);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Abandoned cart processing job failed.', [
            'cart_id' => $this->cartId,
            'record_id' => $this->recordId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
