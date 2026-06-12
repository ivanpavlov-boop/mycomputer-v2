<?php

namespace App\Services\Orders;

use App\Models\Order;

class OrderNumberService
{
    public function generate(): string
    {
        do {
            $number = 'MC'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (Order::query()->where('order_number', $number)->exists());

        return $number;
    }
}
