<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartCheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_to_cart(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonMissingPath('data.items.0.product.purchase_price');
    }

    public function test_update_cart_item(): void
    {
        $item = $this->cartItem();

        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->patchJson('/api/v1/cart/items/'.$item->id, ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 3);
    }

    public function test_remove_cart_item(): void
    {
        $item = $this->cartItem();

        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->deleteJson('/api/v1/cart/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.items_count', 0);
    }

    public function test_clear_cart(): void
    {
        $this->cartItem();

        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->deleteJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items_count', 0);
    }

    public function test_checkout_success_recalculates_totals_and_reduces_stock(): void
    {
        $item = $this->cartItem(quantity: 2);
        $item->product->update(['price' => 100, 'promo_price' => null, 'quantity' => 5]);

        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.grand_total', '208.99')
            ->assertJsonPath('data.customer_email', 'client@example.com');

        $this->assertSame(3, $item->product->fresh()->quantity);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame('converted', Cart::query()->where('session_id', $this->cartSession('test-cart'))->firstOrFail()->status);
    }

    public function test_authenticated_checkout_stores_user_id(): void
    {
        $item = $this->cartItem(quantity: 1);
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->postJson('/api/v1/checkout', array_merge($this->checkoutPayload(), ['email' => 'member@example.com']))
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'customer_email' => 'member@example.com',
        ]);
    }

    public function test_checkout_fails_with_inactive_product(): void
    {
        $item = $this->cartItem();
        $item->product->update(['active' => false]);

        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertStatus(422);
    }

    public function test_checkout_fails_with_insufficient_stock(): void
    {
        $item = $this->cartItem(quantity: 4);
        $item->product->update(['quantity' => 1]);

        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertStatus(422);
    }

    public function test_order_number_generation_is_unique(): void
    {
        $this->seed();

        $first = app(OrderNumberService::class)->generate();
        Order::query()->create(array_merge($this->orderPayload(), ['order_number' => $first]));
        $second = app(OrderNumberService::class)->generate();

        $this->assertNotSame($first, $second);
    }

    private function cartItem(int $quantity = 1)
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->withHeader('X-Cart-Session', $this->cartSession('test-cart'))
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => $quantity])
            ->assertOk();

        return Cart::query()->where('session_id', $this->cartSession('test-cart'))->firstOrFail()->items()->firstOrFail();
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'client@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'notes' => '<b>Please call</b>',
            'terms' => true,
        ];
    }

    private function orderPayload(): array
    {
        return [
            'customer_email' => 'client@example.com',
            'customer_phone' => '0888123456',
            'customer_name' => 'Ivan Petrov',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 10,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'shipping_method' => 'office_delivery',
            'shipping_status' => 'pending',
            'status' => 'pending',
        ];
    }
}
