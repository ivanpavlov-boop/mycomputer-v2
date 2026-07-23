<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_payment_methods(): void
    {
        $this->seed();

        $this->getJson('/api/v1/payments/methods')
            ->assertOk()
            ->assertJsonFragment(['code' => 'cash_on_delivery'])
            ->assertJsonFragment(['code' => 'bank_transfer'])
            ->assertJsonFragment(['code' => 'card'])
            ->assertJsonFragment(['code' => 'leasing']);
    }

    public function test_checkout_with_cash_on_delivery_creates_pending_transaction(): void
    {
        $response = $this->checkout('cash_on_delivery');

        $response->assertCreated()
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment_transactions.0.status', 'pending');
    }

    public function test_checkout_with_bank_transfer_returns_instructions(): void
    {
        $this->checkout('bank_transfer')
            ->assertCreated()
            ->assertJsonPath('data.payment_transactions.0.payment_method.code', 'bank_transfer')
            ->assertJsonPath('data.payment_transactions.0.instructions', 'Очаквайте банкови данни и основание за плащане в потвърждението.');
    }

    public function test_checkout_with_card_placeholder_returns_redirect(): void
    {
        $this->checkout('card')
            ->assertCreated()
            ->assertJsonPath('data.payment_transactions.0.payment_method.code', 'card')
            ->assertJsonPath('data.payment_transactions.0.redirect_url', '/payment/mock-card?order='.Order::query()->firstOrFail()->order_number);
    }

    public function test_checkout_with_leasing_placeholder_returns_redirect(): void
    {
        $this->checkout('leasing')
            ->assertCreated()
            ->assertJsonPath('data.payment_transactions.0.payment_method.code', 'leasing');

        $this->assertStringContainsString('/payment/mock-leasing', PaymentTransaction::query()->firstOrFail()->raw_response['redirect_url']);
    }

    public function test_inactive_payment_method_cannot_be_used(): void
    {
        $this->prepareCart();
        PaymentMethod::query()->where('code', 'card')->update(['status' => 'inactive']);

        $this->withHeader('X-Cart-Session', $this->cartSession('payment-cart'))
            ->postJson('/api/v1/checkout', $this->payload('card'))
            ->assertNotFound();
    }

    public function test_payment_transaction_amount_equals_order_grand_total(): void
    {
        $this->checkout('cash_on_delivery')->assertCreated();

        $order = Order::query()->firstOrFail();
        $transaction = PaymentTransaction::query()->firstOrFail();

        $this->assertSame($order->grand_total, $transaction->amount);
    }

    public function test_mark_order_as_paid_from_service(): void
    {
        $this->checkout('cash_on_delivery')->assertCreated();
        $order = Order::query()->firstOrFail();

        app(PaymentService::class)->markPaid($order);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', PaymentTransaction::query()->firstOrFail()->status);
    }

    public function test_webhook_placeholder_rejects_unknown_provider(): void
    {
        $this->seed();

        $this->postJson('/api/v1/payments/webhook/unknown', [])->assertNotFound();
    }

    public function test_webhook_rejects_missing_signature_for_active_provider(): void
    {
        $this->seed();

        $this->postJson('/api/v1/payments/webhook/manual', ['event' => 'payment.updated'])
            ->assertUnauthorized();
    }

    public function test_webhook_accepts_valid_signature_for_active_provider(): void
    {
        $this->seed();

        $this->withHeaders([
            'X-Webhook-Timestamp' => now()->timestamp,
            'X-Webhook-Signature' => 'test-signature',
            'X-Webhook-Id' => 'payment-event-1',
        ])->postJson('/api/v1/payments/webhook/manual', ['event' => 'payment.updated'])
            ->assertOk()
            ->assertJsonPath('data.signature_validation', 'valid');
    }

    public function test_webhook_replay_is_rejected(): void
    {
        $this->seed();

        $headers = [
            'X-Webhook-Timestamp' => now()->timestamp,
            'X-Webhook-Signature' => 'test-signature',
            'X-Webhook-Id' => 'payment-event-replay',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v1/payments/webhook/manual', ['event' => 'payment.updated'])
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/payments/webhook/manual', ['event' => 'payment.updated'])
            ->assertUnauthorized();
    }

    private function checkout(string $method)
    {
        $this->prepareCart();

        return $this->withHeader('X-Cart-Session', $this->cartSession('payment-cart'))
            ->postJson('/api/v1/checkout', $this->payload($method));
    }

    private function prepareCart(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->withHeader('X-Cart-Session', $this->cartSession('payment-cart'))
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();
        Cart::query()->where('session_id', $this->cartSession('payment-cart'))->firstOrFail();
    }

    private function payload(string $method): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'pay@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'payment_method' => $method,
            'shipping_provider' => 'manual',
            'shipping_method' => 'address',
            'delivery_type' => 'address',
            'city' => 'Sofia',
            'terms' => true,
        ];
    }
}
