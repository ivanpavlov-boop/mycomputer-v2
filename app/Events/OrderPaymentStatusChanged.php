<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OrderPaymentStatusChanged
{
    use Dispatchable;

    public function __construct(public int $orderId, public string $paymentStatus) {}
}
