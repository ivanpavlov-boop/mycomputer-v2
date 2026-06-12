<?php

namespace App\Services\Erp\Microinvest;

use App\Models\Order;

class MicroinvestPaymentMapper
{
    public function __construct(
        private readonly MicroinvestConfig $config = new MicroinvestConfig,
    ) {}

    public function map(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'payment_method' => $order->payment_method,
            'microinvest_payment_method' => $this->config->paymentMethodMapping[$order->payment_method] ?? $order->payment_method,
            'payment_status' => $order->payment_status,
            'amount' => (float) $order->grand_total,
            'currency' => 'BGN',
        ];
    }
}
