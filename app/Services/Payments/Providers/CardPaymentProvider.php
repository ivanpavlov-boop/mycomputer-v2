<?php

namespace App\Services\Payments\Providers;

use App\Exceptions\CardPaymentProviderUnavailableException;
use App\Models\Order;
use App\Services\Payments\PaymentInitiationContext;

class CardPaymentProvider extends ManualPaymentProvider
{
    public function isOperational(): bool
    {
        return false;
    }

    public function initiatePayment(Order $order, PaymentInitiationContext $context): array
    {
        throw new CardPaymentProviderUnavailableException;
    }
}
