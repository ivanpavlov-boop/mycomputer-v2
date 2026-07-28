<?php

namespace App\Services\Payments;

use App\Models\Order;
use LogicException;

class PaymentProviderIdempotencyService
{
    private const PURPOSE = 'payment-provider-attempt-v1';

    public function derive(string $clientKeyHash, Order $order, string $methodCode): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                self::PURPOSE,
                $clientKeyHash,
                (string) $order->getKey(),
                $methodCode,
            ]),
            $this->key(),
        );
    }

    private function key(): string
    {
        $applicationKey = (string) config('app.key');

        if (str_starts_with($applicationKey, 'base64:')) {
            $decoded = base64_decode(substr($applicationKey, 7), true);

            if ($decoded === false) {
                throw new LogicException('The application key cannot derive payment provider identity.');
            }

            $applicationKey = $decoded;
        }

        if ($applicationKey === '') {
            throw new LogicException('The application key is required for payment provider identity.');
        }

        return hash_hmac('sha256', self::PURPOSE, $applicationKey, true);
    }
}
