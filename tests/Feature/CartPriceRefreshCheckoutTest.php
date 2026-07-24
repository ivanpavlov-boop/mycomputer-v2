<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CartPriceRefreshCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_commits_price_refresh_and_returns_review_before_side_effects(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update(['price' => 100, 'regular_price' => 100, 'quantity' => 5]);
        $cart = $this->cartWithItem($product, 80, 2, 'price-review');
        $customerCount = Customer::query()->count();
        $orderCount = Order::query()->count();
        $stock = $product->quantity;
        Event::fake([OrderCreated::class]);
        Mail::fake();
        Queue::fake();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'error' => [
                    'code' => 'cart_price_changed',
                    'message' => 'Cart prices changed. Please review your cart and try again.',
                    'details' => null,
                ],
            ]);

        $this->assertSame('100.00', $cart->items()->firstOrFail()->unit_price);
        $this->assertSame('200.00', $cart->items()->firstOrFail()->total_price);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertSame($orderCount, Order::query()->count());
        $this->assertSame($stock, $product->fresh()->quantity);
        Event::assertNotDispatched(OrderCreated::class);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_customer_can_review_refreshed_cart_then_retry_checkout(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update(['price' => 100, 'regular_price' => 100, 'quantity' => 5]);
        $cart = $this->cartWithItem($product, 80, 2, 'price-review-retry');

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertStatus(409);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.subtotal', 200);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.0.total_price', '200.00');

        $this->assertSame('converted', $cart->fresh()->status);
        $this->assertSame(3, $product->fresh()->quantity);
    }

    private function cartWithItem(Product $product, float $storedPrice, int $quantity, string $name): Cart
    {
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $storedPrice,
            'total_price' => $storedPrice * $quantity,
        ]);

        return $cart;
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'pricing@example.test',
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
