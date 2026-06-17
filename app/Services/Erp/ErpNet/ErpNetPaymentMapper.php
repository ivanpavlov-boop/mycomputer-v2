<?php

namespace App\Services\Erp\ErpNet;

use App\Models\Order;

class ErpNetPaymentMapper
{
    public function __construct(
        private readonly ErpNetConfig $config = new ErpNetConfig,
    ) {}

    public function map(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'payment_method' => $order->payment_method,
            'erp_net_payment_method' => $this->config->paymentMethodMapping[$order->payment_method] ?? $order->payment_method,
            'payment_status' => $order->payment_status,
            'amount' => (float) $order->grand_total,
            'currency' => 'EUR',
        ];
    }
}
