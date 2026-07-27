<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OrderPaymentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $orderId, public string $paymentStatus) {}
}
