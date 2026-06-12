<?php

namespace App\Services\Payments\Providers;

use App\Models\Order;

class CardPaymentProvider extends ManualPaymentProvider
{
    public function initiatePayment(Order $order, array $data): array
    {
        $response = parent::initiatePayment($order, $data);
        $response['redirect_url'] = '/payment/mock-card?order='.$order->order_number;
        $response['raw_response']['provider'] = 'card_placeholder';

        return $response;
    }
}
