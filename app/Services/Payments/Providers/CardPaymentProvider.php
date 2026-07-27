<?php

namespace App\Services\Payments\Providers;

use App\Exceptions\CardPaymentProviderUnavailableException;
use App\Models\Order;

class CardPaymentProvider extends ManualPaymentProvider
{
    public function isOperational(): bool
    {
        return false;
    }

    public function initiatePayment(Order $order, array $data): array
    {
        throw new CardPaymentProviderUnavailableException;
    }
}
