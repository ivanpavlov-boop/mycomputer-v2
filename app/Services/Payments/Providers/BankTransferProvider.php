<?php

namespace App\Services\Payments\Providers;

use App\Models\Order;

class BankTransferProvider extends ManualPaymentProvider
{
    public function initiatePayment(Order $order, array $data): array
    {
        return parent::initiatePayment($order, [
            'instructions' => $data['instructions'] ?? 'Моля, преведете сумата по банковата сметка с основание номер на поръчка '.$order->order_number.'.',
        ]);
    }
}
