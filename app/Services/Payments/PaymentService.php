<?php

namespace App\Services\Payments;

use App\Events\OrderPaymentStatusChanged;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use App\Services\Payments\Providers\BankTransferProvider;
use App\Services\Payments\Providers\CardPaymentProvider;
use App\Services\Payments\Providers\CashOnDeliveryProvider;
use App\Services\Payments\Providers\LeasingPaymentProvider;
use App\Services\Payments\Providers\ManualPaymentProvider;

class PaymentService
{
    public function provider(PaymentMethod|PaymentProvider|string|null $provider): PaymentProviderInterface
    {
        $code = $provider instanceof PaymentMethod ? $provider->code : ($provider instanceof PaymentProvider ? $provider->code : $provider);

        return match ($code) {
            'cash_on_delivery' => new CashOnDeliveryProvider,
            'bank_transfer' => new BankTransferProvider,
            'card' => new CardPaymentProvider,
            'leasing' => new LeasingPaymentProvider,
            default => new ManualPaymentProvider,
        };
    }

    public function activeMethod(string $code): PaymentMethod
    {
        return PaymentMethod::query()
            ->with('provider')
            ->where('code', $code)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('payment_provider_id')->orWhereHas('provider', fn ($provider) => $provider->where('status', 'active')))
            ->firstOrFail();
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
            'raw_response' => $response,
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
