<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\Providers\CardPaymentProvider;
use Tests\Fakes\FakeCardPaymentProvider;

trait BuildsPaymentAttemptFixtures
{
    protected function enableTestCard(): FakeCardPaymentProvider
    {
        config()->set('payments.methods.card.enabled', true);
        config()->set(
            'payments.methods.card.approved_redirect_hosts',
            ['payments.example.test'],
        );
        $this->seed();
        PaymentMethod::query()
            ->where('code', 'card')
            ->update(['status' => 'active']);

        $fake = new FakeCardPaymentProvider;
        $this->app->instance(CardPaymentProvider::class, $fake);

        return $fake;
    }

    protected function paymentOrder(
        ?User $user = null,
        string $method = 'card',
        string $status = 'pending',
        string $paymentStatus = 'failed',
    ): Order {
        return Order::query()->create([
            'order_number' => 'ORD-'.strtoupper(substr(md5(uniqid('', true)), 0, 12)),
            'user_id' => $user?->getKey(),
            'customer_email' => $user?->email ?? 'guest@example.test',
            'customer_phone' => '+359888123456',
            'customer_name' => 'Payment Test',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'payment_method' => $method,
            'payment_status' => $paymentStatus,
            'shipping_method' => 'manual',
            'shipping_status' => 'pending',
            'status' => $status,
        ]);
    }

    protected function paymentTransaction(
        Order $order,
        string $status = 'failed',
        ?string $reference = null,
    ): PaymentTransaction {
        $method = PaymentMethod::query()
            ->where('code', $order->payment_method)
            ->firstOrFail();

        return $order->paymentTransactions()->create([
            'payment_provider_id' => $method->payment_provider_id,
            'payment_method_id' => $method->getKey(),
            'transaction_id' => $reference ?? 'OLD-'.strtoupper(substr(md5(uniqid('', true)), 0, 12)),
            'amount' => $order->grand_total,
            'currency' => 'EUR',
            'status' => $status,
            'raw_request' => ['payment_method_code' => $method->code],
            'raw_response' => [],
            'paid_at' => $status === 'paid' ? now() : null,
            'failed_at' => $status === 'failed' ? now() : null,
        ]);
    }

    protected function paymentAttemptKey(string $name): string
    {
        return rtrim(strtr(
            base64_encode(hash('sha256', 'payment-attempt:'.$name, true)),
            '+/',
            '-_',
        ), '=');
    }
}
