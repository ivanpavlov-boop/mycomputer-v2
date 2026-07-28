<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentRetryNotAllowedException;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;

class PaymentRetryPolicy
{
    public function __construct(
        private readonly PaymentMethodAvailabilityService $availability,
    ) {}

    public function decide(
        Order $order,
        PaymentMethod $method,
        ?PaymentTransaction $transaction,
    ): PaymentRetryDecision {
        if (! in_array($order->status, ['pending', 'confirmed'], true)) {
            throw new PaymentRetryNotAllowedException;
        }

        if ($order->payment_method !== $method->code) {
            throw new PaymentRetryNotAllowedException;
        }

        if ($method->type !== 'online' || $method->code !== 'card') {
            throw new PaymentRetryNotAllowedException(
                'payment_retry_not_supported',
                'Този начин на плащане не поддържа нов платежен опит.',
                422,
            );
        }

        $this->availability->requireAvailable($method->code);

        if (! $transaction) {
            throw new PaymentRetryNotAllowedException;
        }

        return match ($transaction->status) {
            'pending', 'authorized' => PaymentRetryDecision::existing($transaction),
            'failed', 'cancelled' => PaymentRetryDecision::initiate(),
            'paid' => throw new PaymentRetryNotAllowedException(
                'payment_already_paid',
                'Поръчката вече е платена.',
            ),
            'refunded' => throw new PaymentRetryNotAllowedException,
            default => throw new PaymentRetryNotAllowedException,
        };
    }
}
