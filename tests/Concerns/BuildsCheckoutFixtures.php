<?php

namespace Tests\Concerns;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait BuildsCheckoutFixtures
{
    private bool $checkoutFixtureSeeded = false;

    protected function prepareCheckoutCart(
        string $name,
        int $quantity = 1,
        ?User $user = null,
    ): Cart {
        if (! $this->checkoutFixtureSeeded) {
            $this->seed();
            $this->checkoutFixtureSeeded = true;
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
        $request = $user ? $this->actingAs($user, 'sanctum') : $this;

        $request
            ->withHeader('X-Cart-Session', $this->cartSession($name))
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->getKey(),
                'quantity' => $quantity,
            ])
            ->assertOk();

        return Cart::query()
            ->where('session_id', $this->cartSession($name))
            ->firstOrFail();
    }

    protected function submitCheckout(
        Cart $cart,
        string $keyName,
        array $overrides = [],
        ?User $user = null,
    ): TestResponse {
        $request = $user ? $this->actingAs($user, 'sanctum') : $this;

        return $request
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey($keyName))
            ->postJson('/api/v1/checkout', $this->checkoutPayload($overrides));
    }

    protected function checkoutPayload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'checkout@example.test',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'notes' => 'Call before delivery',
            'terms' => true,
        ], $overrides);
    }
}
