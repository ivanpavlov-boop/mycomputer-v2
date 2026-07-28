<?php

namespace App\Services\Payments;

use App\Events\OrderPaymentStatusChanged;
use App\Exceptions\PaymentMethodUnavailableException;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\Payments\Contracts\PaymentProviderInterface;

class PaymentService
{
    public function __construct(
        private readonly PaymentMethodAvailabilityService $availability,
        private readonly PaymentProviderResolver $providers,
    ) {}

    public function activeMethod(string $code): PaymentMethod
    {
        return $this->availability->requireAvailable($code);
    }

    public function provider(PaymentMethod|string $method): PaymentProviderInterface
    {
        return $this->providers->resolve($method)
            ?? throw new PaymentMethodUnavailableException;
    }

    public function initiate(Order $order, string $methodCode, array $data = []): PaymentTransaction
    {
        $method = $this->activeMethod($methodCode);
        $response = $this->provider($method)->initiatePayment($order, [
            'instructions' => $method->instructions,
        ] + $data);

        $transaction = $order->paymentTransactions()->create([
            'payment_provider_id' => $method->payment_provider_id,
            'payment_method_id' => $method->id,
            'transaction_id' => $response['transaction_id'] ?? null,
            'amount' => $order->grand_total,
            'currency' => 'EUR',
            'status' => $response['status'] ?? 'pending',
            'raw_request' => ['payment_method_code' => $methodCode],
            'raw_response' => $methodCode === 'leasing'
                ? ($response['raw_response'] ?? ['mode' => 'manual_leasing_application'])
                : $response,
            'paid_at' => ($response['status'] ?? null) === 'paid' ? now() : null,
            'failed_at' => ($response['status'] ?? null) === 'failed' ? now() : null,
        ]);

        $order->update([
            'payment_method' => $method->code,
            'payment_status' => $transaction->status,
        ]);
        OrderPaymentStatusChanged::dispatch($order->id, $order->payment_status);

        return $transaction->load(['method', 'provider']);
    }

    public function markPaid(Order $order): void
    {
        $order->paymentTransactions()->latest()->first()?->update(['status' => 'paid', 'paid_at' => now()]);
        $order->update(['payment_status' => 'paid']);
        OrderPaymentStatusChanged::dispatch($order->id, 'paid');
    }

    public function markFailed(Order $order): void
    {
        $order->paymentTransactions()->latest()->first()?->update(['status' => 'failed', 'failed_at' => now()]);
        $order->update(['payment_status' => 'failed']);
        OrderPaymentStatusChanged::dispatch($order->id, 'failed');
    }
}
