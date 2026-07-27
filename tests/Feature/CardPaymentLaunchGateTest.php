<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Jobs\ConversionTrackingJob;
use App\Jobs\SendEmailJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\Orders\CheckoutConfirmationService;
use App\Services\Payments\Providers\CardPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\Fakes\FakeCardPaymentProvider;
use Tests\TestCase;

class CardPaymentLaunchGateTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_disabled_card_rejection_does_not_create_a_cart_or_idempotency_identity(): void
    {
        config()->set('payments.methods.card.enabled', false);
        $this->seed();
        PaymentMethod::query()->where('code', 'card')->update(['status' => 'active']);
        $fake = new FakeCardPaymentProvider;
        $this->app->instance(CardPaymentProvider::class, $fake);

        $this->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('card-without-cart'))
            ->postJson('/api/v1/checkout', $this->checkoutPayload(['payment_method' => 'card']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'payment_method_unavailable');

        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('checkout_idempotency_records', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $fake->initiationCount);
    }

    public function test_disabled_card_checkout_is_rejected_before_every_checkout_side_effect(): void
    {
        config()->set('payments.methods.card.enabled', false);
        $cart = $this->prepareCheckoutCart('disabled-card');
        PaymentMethod::query()->where('code', 'card')->update(['status' => 'active']);
        $fake = new FakeCardPaymentProvider;
        $this->app->instance(CardPaymentProvider::class, $fake);
        Queue::fake([ConversionTrackingJob::class, SendEmailJob::class]);
        Event::fake([OrderCreated::class]);

        $product = $cart->items()->firstOrFail()->product;
        $stockBefore = $product->quantity;
        $customerCount = Customer::query()->count();

        $this->submitCheckout($cart, 'disabled-card', ['payment_method' => 'card'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'payment_method_unavailable')
            ->assertJsonPath('error.message', 'Избраният начин на плащане не е наличен.');

        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('order_shipments', 0);
        $this->assertDatabaseCount('promotion_redemptions', 0);
        $this->assertDatabaseCount('checkout_idempotency_records', 0);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);
        $this->assertSame($stockBefore, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(0, $fake->initiationCount);
        Queue::assertNothingPushed();
        Event::assertNotDispatched(OrderCreated::class);
    }

    public function test_operational_test_card_is_available_and_idempotent_checkout_calls_it_once(): void
    {
        config()->set('payments.methods.card.enabled', true);
        $cart = $this->prepareCheckoutCart('enabled-test-card');
        PaymentMethod::query()->where('code', 'card')->update(['status' => 'active']);
        $fake = new FakeCardPaymentProvider;
        $this->app->instance(CardPaymentProvider::class, $fake);

        $methods = $this->getJson('/api/v1/payments/methods')
            ->assertOk()
            ->assertJsonFragment(['code' => 'card'])
            ->assertJsonMissing(['settings' => []]);
        $this->assertStringNotContainsString('settings', $methods->getContent());
        $this->assertStringNotContainsString('credentials', $methods->getContent());
        $this->assertStringNotContainsString('operational', $methods->getContent());

        $first = $this->submitCheckout($cart, 'enabled-test-card', ['payment_method' => 'card']);
        $replay = $this->submitCheckout($cart, 'enabled-test-card', ['payment_method' => 'card']);

        $first->assertCreated()
            ->assertJsonPath('data.payment_method', 'card')
            ->assertJsonPath('data.idempotent_replay', false)
            ->assertJsonMissingPath('data.payment_transactions');
        $replay->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('checkout_idempotency_records', 1);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 2);
        $this->assertSame(1, $fake->initiationCount);

        $order = Order::query()->sole();
        $transaction = PaymentTransaction::query()->sole();
        $redirect = $transaction->raw_response['redirect_url'];
        $this->assertSame('https://payments.example.test/continue', $redirect);
        $this->assertStringNotContainsString($order->order_number, $redirect);
        $this->assertStringNotContainsString($order->customer_email, $redirect);

        $cookie = $first->getCookie(CheckoutConfirmationService::COOKIE_NAME, false);
        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_removed_public_payment_initiation_endpoint_returns_not_found(): void
    {
        $this->seed();

        $this->postJson('/api/v1/payments/initiate', [
            'order_id' => 1,
            'payment_method_code' => 'cash_on_delivery',
        ])->assertNotFound();

        $this->assertDatabaseCount('payment_transactions', 0);
    }
}
