<?php

namespace App\Services\Payments\Providers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use Illuminate\Support\Str;

class ManualPaymentProvider implements PaymentProviderInterface
{
    public function initiatePayment(Order $order, array $data): array
    {
        return [
            'status' => 'pending',
            'transaction_id' => 'PAY-'.Str::upper(Str::random(12)),
            'redirect_url' => null,
            'instructions' => $data['instructions'] ?? null,
            'raw_response' => ['mode' => 'manual_placeholder'],
        ];
    }

    public function verifyPayment(array $data): array
    {
        return ['status' => 'pending', 'raw_response' => $data];
    }

    public function refundPayment(PaymentTransaction $transaction, array $data = []): array
    {
        return ['status' => 'refunded', 'raw_response' => ['placeholder' => true] + $data];
    }

    public function cancelPayment(PaymentTransaction $transaction): array
    {
        return ['status' => 'cancelled', 'raw_response' => ['placeholder' => true]];
    }

    public function getPaymentStatus(PaymentTransaction $transaction): array
    {
        return ['status' => $transaction->status];
    }
}
