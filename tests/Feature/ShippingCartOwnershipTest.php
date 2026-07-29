<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ShippingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShippingCartOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ShippingSeeder::class);
    }

    public function test_guest_owned_cart_supplies_authoritative_shipping_subtotal(): void
    {
        $cart = $this->cart('shipping-owned-guest', productPrice: 600);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $this->payload(['cart_id' => $cart->id]))
            ->assertOk()
            ->assertJsonPath('data.shipping_price', '0.00');
    }

    public function test_authenticated_owner_can_use_owned_cart(): void
    {
        $owner = User::factory()->create();
        $cart = $this->cart('shipping-owned-user', $owner, 600);
        Sanctum::actingAs($owner);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $this->payload(['cart_id' => $cart->id]))
            ->assertOk()
            ->assertJsonPath('data.shipping_price', '0.00');
    }

    public function test_foreign_authenticated_cart_cannot_influence_shipping_subtotal(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $cart = $this->cart('shipping-foreign-user', $owner, 600);
        Sanctum::actingAs($other);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $this->payload(['cart_id' => $cart->id]))
            ->assertForbidden();
    }

    public function test_mismatched_cart_id_is_rejected(): void
    {
        $owned = $this->cart('shipping-mismatch-owned', productPrice: 600);
        $other = $this->cart('shipping-mismatch-other', productPrice: 600);

        $this->withHeader('X-Cart-Session', $owned->session_id)
            ->postJson('/api/v1/shipping/calculate', $this->payload(['cart_id' => $other->id]))
            ->assertUnprocessable();
    }

    public function test_cart_id_without_session_is_rejected(): void
    {
        $cart = $this->cart('shipping-id-without-session', productPrice: 600);

        $this->postJson('/api/v1/shipping/calculate', $this->payload(['cart_id' => $cart->id]))
            ->assertUnprocessable();
    }

    public function test_direct_non_cart_calculation_keeps_safe_zero_subtotal_contract(): void
    {
        $this->postJson('/api/v1/shipping/calculate', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.shipping_price', '8.99');
    }

    private function cart(
        string $name,
        ?User $user = null,
        float $productPrice = 100,
    ): Cart {
        $product = Product::factory()->supplierPublished()->create([
            'price' => $productPrice,
            'regular_price' => $productPrice,
            'quantity' => 10,
        ]);
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'user_id' => $user?->id,
            'customer_email' => $user?->email,
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $productPrice,
            'total_price' => $productPrice,
        ]);

        return $cart;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'speedy',
            'delivery_type' => 'address',
            'shipping_method' => 'address',
            'city' => 'Sofia',
            'address' => 'bul. Bulgaria 1',
        ], $overrides);
    }
}
