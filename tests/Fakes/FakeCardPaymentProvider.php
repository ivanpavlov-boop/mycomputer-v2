<?php

namespace Tests\Fakes;

use App\Exceptions\PaymentProviderDefinitiveFailureException;
use App\Exceptions\PaymentProviderIndeterminateException;
use App\Models\Order;
use App\Services\Payments\PaymentInitiationContext;
use App\Services\Payments\Providers\ManualPaymentProvider;

class FakeCardPaymentProvider extends ManualPaymentProvider
{
    public int $initiationCount = 0;

    public ?string $failureMode = null;

    /** @var array<int, string|null> */
    public array $providerIdempotencyKeys = [];

    public function isOperational(): bool
    {
        return true;
    }

    public function initiatePayment(Order $order, PaymentInitiationContext $context): array
    {
        $this->initiationCount++;
        $this->providerIdempotencyKeys[] = $context->providerIdempotencyKey;

        if ($this->failureMode === 'definitive') {
            throw new PaymentProviderDefinitiveFailureException;
        }

        if ($this->failureMode === 'indeterminate') {
            throw new PaymentProviderIndeterminateException;
        }

        return [
            'status' => 'pending',
            'transaction_id' => $context->isRetry
                ? 'TEST-CARD-'.strtoupper(substr(
                    hash('sha256', 'test-provider-reference|'.$context->providerIdempotencyKey),
                    0,
                    16,
                ))
                : 'TEST-CARD-0001',
            'redirect_url' => 'https://payments.example.test/continue',
            'instructions' => null,
            'raw_response' => ['provider' => 'test_card'],
        ];
    }
}
