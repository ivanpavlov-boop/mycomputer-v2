<?php

namespace App\Services\Payments\Contracts;

use App\Models\Order;
use App\Models\PaymentTransaction;

interface PaymentProviderInterface
{
    public function initiatePayment(Order $order, array $data): array;

    public function verifyPayment(array $data): array;

    public function refundPayment(PaymentTransaction $transaction, array $data = []): array;

    public function cancelPayment(PaymentTransaction $transaction): array;

    public function getPaymentStatus(PaymentTransaction $transaction): array;
}
