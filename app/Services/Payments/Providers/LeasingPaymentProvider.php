<?php

namespace App\Services\Payments\Providers;

use App\Models\Order;

class LeasingPaymentProvider extends ManualPaymentProvider
{
    public function initiatePayment(Order $order, array $data): array
    {
        $response = parent::initiatePayment($order, $data);
        $response['redirect_url'] = '/payment/mock-leasing?order='.$order->order_number;
        $response['instructions'] = 'Ще бъдете пренасочени към лизингова заявка.';
        $response['raw_response']['provider'] = 'leasing_placeholder';

        return $response;
    }
}
