<?php

namespace App\Services\Payments\Providers;

use App\Models\Order;
use App\Services\Payments\PaymentInitiationContext;

class LeasingPaymentProvider extends ManualPaymentProvider
{
    public function initiatePayment(Order $order, PaymentInitiationContext $context): array
    {
        return [
            'status' => 'pending',
            'transaction_id' => null,
            'redirect_url' => null,
            'instructions' => 'Получихме заявката Ви за покупка на изплащане. Наш служител ще се свърже с Вас.',
            'raw_response' => ['mode' => 'manual_leasing_application'],
        ];
    }
}
