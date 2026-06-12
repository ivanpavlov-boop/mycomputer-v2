<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OrderCancelled
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
