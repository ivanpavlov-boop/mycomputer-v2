<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CheckoutConfirmationCapability;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CheckoutSuccessDataSafetyTest extends TestCase
{
    use RefreshDatabase;

    private bool $seeded = false;

    public function test_checkout_response_is_minimal_and_contains_no_customer_or_capability_data(): void
    {
        $response = $this->checkout('minimal-response');

        $response->assertCreated()->assertExactJson([
            'data' => [
                'accepted' => true,
                'order_number' => Order::query()->sole()->order_number,
                'grand_total' => Order::query()->sole()->grand_total,
                'currency' => 'EUR',
                'payment_method' => 'cash_on_delivery',
                'payment_status' => 'pending',
            ],
        ]);

        $serialized = $response->getContent();

        foreach ([
            'customer_email',
            'customer_phone',
            'customer_name',
            'billing_address',
            'shipping_address',
            'company_name',
            'vat_number',
            'confirmation_token',
            'token_hash',
            'cart_session_id',
            'payment_transactions',
            'raw_response',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        $token = $response->getCookie(
            CheckoutConfirmationService::COOKIE_NAME,
            false,
        )?->getValue();

        $this->assertIsString($token);
        $this->assertStringNotContainsString($token, $serialized);
    }

    public function test_validation_readiness_stock_price_and_payment_rejections_create_no_capability(): void
    {
        $this->postJson('/api/v1/checkout', [])
            ->assertUnprocessable();
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);

        $inactive = $this->preparedCart('inactive-rejection');
        $inactive->items()->firstOrFail()->product->update(['active' => false]);
        $this->withHeader('X-Cart-Session', $inactive->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertConflict();
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);

        $stock = $this->preparedCart('stock-rejection', 4);
        $stock->items()->firstOrFail()->product->update(['quantity' => 1]);
        $this->withHeader('X-Cart-Session', $stock->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertConflict();
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);

        $price = $this->preparedCart('price-rejection');
        $price->items()->firstOrFail()->update([
            'unit_price' => 1,
            'total_price' => 1,
        ]);
        $this->withHeader('X-Cart-Session', $price->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertConflict();
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);

        $payment = $this->preparedCart('payment-rejection');
        PaymentMethod::query()->where('code', 'cash_on_delivery')->update(['status' => 'inactive']);
        $this->withHeader('X-Cart-Session', $payment->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertNotFound();
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);
    }

    public function test_capability_issue_failure_rolls_back_order_customer_stock_and_cart_changes(): void
    {
        $cart = $this->preparedCart('atomic-rollback');
        $product = $cart->items()->firstOrFail()->product;
        $stockBefore = $product->quantity;
        $customerCount = Customer::query()->count();
        $confirmationService = Mockery::mock(CheckoutConfirmationService::class);
        $confirmationService->shouldReceive('issue')
            ->once()
            ->andThrow(new RuntimeException('Synthetic capability failure.'));
        $this->app->instance(CheckoutConfirmationService::class, $confirmationService);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertServerError();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_confirmation_capabilities', 0);
        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertSame($stockBefore, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_capability_is_created_inside_checkout_transaction_before_success_side_effects(): void
    {
        $source = file_get_contents(app_path('Services/Orders/CheckoutService.php'));
        $issuePosition = strpos($source, '$this->checkoutConfirmations->issue($order)');
        $jobPosition = strpos($source, 'ConversionTrackingJob::dispatch($order->id)');
        $eventPosition = strpos($source, 'OrderCreated::dispatch($order->id)');

        $this->assertIsInt($issuePosition);
        $this->assertIsInt($jobPosition);
        $this->assertIsInt($eventPosition);
        $this->assertLessThan($jobPosition, $issuePosition);
        $this->assertLessThan($eventPosition, $issuePosition);
        $this->assertStringContainsString('}, 3);', $source);
    }

    public function test_successful_checkout_commits_exactly_one_order_and_capability(): void
    {
        $this->checkout('single-commit')->assertCreated();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, CheckoutConfirmationCapability::query()->count());
        $this->assertSame(
            Order::query()->sole()->id,
            CheckoutConfirmationCapability::query()->sole()->order_id,
        );
    }

    private function checkout(string $sessionName)
    {
        $cart = $this->preparedCart($sessionName);

        return $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload());
    }

    private function preparedCart(string $sessionName, int $quantity = 1): Cart
    {
        if (! $this->seeded) {
            $this->seed();
            $this->seeded = true;
        }

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update([
            'active' => true,
            'workflow_status' => 'published',
            'product_status' => 'active',
            'published_at' => now(),
            'price' => 100,
            'regular_price' => 100,
            'promo_price' => null,
            'quantity' => 100,
        ]);

        $this->withHeader('X-Cart-Session', $this->cartSession($sessionName))
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ])
            ->assertOk();

        return Cart::query()
            ->where('session_id', $this->cartSession($sessionName))
            ->firstOrFail();
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'safety@example.test',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'terms' => true,
        ];
    }
}
