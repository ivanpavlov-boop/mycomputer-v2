<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OrderCreated
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
