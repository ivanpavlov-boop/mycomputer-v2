<?php

namespace Tests\Fakes;

use App\Models\Order;
use App\Services\Payments\Providers\ManualPaymentProvider;

class FakeCardPaymentProvider extends ManualPaymentProvider
{
    public int $initiationCount = 0;

    public function isOperational(): bool
    {
        return true;
    }

    public function initiatePayment(Order $order, array $data): array
    {
        $this->initiationCount++;

        return [
            'status' => 'pending',
            'transaction_id' => 'TEST-CARD-0001',
            'redirect_url' => 'https://payments.example.test/continue',
            'instructions' => null,
            'raw_response' => ['provider' => 'test_card'],
        ];
    }
}
