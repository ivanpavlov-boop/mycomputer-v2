<?php

namespace App\Services\Payments\Providers;

use App\Models\Order;
use App\Services\Payments\PaymentInitiationContext;

class BankTransferProvider extends ManualPaymentProvider
{
    public function initiatePayment(Order $order, PaymentInitiationContext $context): array
    {
        return parent::initiatePayment($order, new PaymentInitiationContext(
            instructions: $context->instructions
                ?? 'Моля, преведете сумата по банковата сметка с основание номер на поръчка '.$order->order_number.'.',
        ));
    }
}
