<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\PcBuild;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartOwnershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_blank_and_valid_sessions_follow_the_uuid_contract(): void
    {
        $missing = $this->getJson('/api/v1/cart')
            ->assertOk()
            ->json('data.cart_session_id');

        $this->assertIsString($missing);
        $this->assertTrue(Str::isUuid($missing));
        $this->assertDatabaseHas('carts', ['session_id' => $missing, 'user_id' => null]);

        $blank = $this->withHeader('X-Cart-Session', '   ')
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->json('data.cart_session_id');

        $this->assertIsString($blank);
        $this->assertTrue(Str::isUuid($blank));
        $this->assertNotSame($missing, $blank);

        $expected = Cart::query()->create([
            'session_id' => $this->cartSession('valid-session'),
            'status' => 'active',
        ]);

        $this->withHeader('X-Cart-Session', $expected->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.id', $expected->id)
            ->assertJsonPath('data.cart_session_id', $expected->session_id);
    }

    public function test_malformed_sessions_fail_before_any_cart_lookup_or_creation(): void
    {
        $invalidSessions = [
            'not-a-uuid',
            str_repeat('a', 37),
            ' '.$this->cartSession('padded'),
            $this->cartSession('uppercase'),
            'https://example.invalid/cart',
            '123',
            '{"cart":"id"}',
            $this->cartSession('first').','.$this->cartSession('second'),
        ];
        $invalidSessions[3] = strtoupper($invalidSessions[3]);

        foreach ($invalidSessions as $invalidSession) {
            $response = $this->withHeader('X-Cart-Session', $invalidSession)
                ->getJson('/api/v1/cart')
                ->assertUnprocessable()
                ->assertJsonPath('error.message', 'Invalid cart session.');

            $this->assertStringNotContainsString($invalidSession, $response->getContent());
        }

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_ownership_matrix_and_anonymous_claim_preserve_cart_state(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $other = User::factory()->create(['email' => 'other@example.test']);
        $ownedCart = $this->cart('owned-cart', $owner);

        $this->withHeader('X-Cart-Session', $ownedCart->session_id)
            ->getJson('/api/v1/cart')
            ->assertForbidden()
            ->assertJsonPath('error.message', 'Cart access is not allowed.');

        $this->actingAs($other, 'sanctum')
            ->withHeader('X-Cart-Session', $ownedCart->session_id)
            ->getJson('/api/v1/cart')
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Cart-Session', $ownedCart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.id', $ownedCart->id);

        Auth::forgetGuards();

        $product = Product::factory()->create();
        $bundle = $this->bundle();
        $anonymousCart = $this->cart('anonymous-claim', coupon: 'KEEP-ME');
        $item = $anonymousCart->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 50,
            'total_price' => 100,
        ]);
        $bundleItem = $anonymousCart->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => [],
            'quantity' => 1,
            'unit_price' => 25,
            'total_price' => 25,
        ]);

        $this->actingAs($other, 'sanctum')
            ->withHeader('X-Cart-Session', $anonymousCart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk();

        $claimed = $anonymousCart->fresh();

        $this->assertSame($other->id, $claimed->user_id);
        $this->assertSame($other->email, $claimed->customer_email);
        $this->assertSame('KEEP-ME', $claimed->coupon_code);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'cart_id' => $claimed->id, 'quantity' => 2]);
        $this->assertDatabaseHas('cart_bundle_items', ['id' => $bundleItem->id, 'cart_id' => $claimed->id]);
    }

    public function test_only_one_user_can_claim_an_anonymous_cart(): void
    {
        $cart = $this->cart('competing-claim');
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first, 'sanctum')
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk();

        $this->actingAs($second, 'sanctum')
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertForbidden();

        $this->assertSame($first->id, $cart->fresh()->user_id);
    }

    public function test_cart_and_bundle_mutations_share_the_foreign_owner_rejection(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $bundle = $this->bundle();
        $cart = $this->cart('protected-mutations', $owner, 'UNCHANGED');
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 20,
            'total_price' => 20,
        ]);

        $this->actingAs($other, 'sanctum');

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertForbidden();
        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 2])
            ->assertForbidden();
        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->deleteJson("/api/v1/cart/items/{$item->id}")
            ->assertForbidden();
        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->deleteJson('/api/v1/cart')
            ->assertForbidden();
        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/cart/coupon', ['code' => 'NOPE'])
            ->assertForbidden();
        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertForbidden();

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'user_id' => $owner->id,
            'coupon_code' => 'UNCHANGED',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
        $this->assertDatabaseCount('cart_bundle_items', 0);

        $foreignCart = $this->cart('foreign-item');
        $foreignItem = $foreignCart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 20,
            'total_price' => 20,
        ]);
        Auth::forgetGuards();

        $this->withHeader('X-Cart-Session', $this->cartSession('membership-cart'))
            ->patchJson("/api/v1/cart/items/{$foreignItem->id}", ['quantity' => 2])
            ->assertNotFound();
    }

    public function test_cross_user_checkout_fails_before_any_checkout_side_effect(): void
    {
        Event::fake();
        Queue::fake();
        Mail::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create(['quantity' => 8]);
        $cart = $this->cart('protected-checkout', $owner, 'KEEP');
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 50,
            'total_price' => 100,
        ]);
        $cartState = $cart->getAttributes();
        $stock = $product->quantity;

        $this->actingAs($other, 'sanctum')
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertForbidden()
            ->assertJsonPath('error.message', 'Cart access is not allowed.');

        foreach ([
            'orders',
            'order_items',
            'customers',
            'order_shipments',
            'payment_transactions',
            'promotion_redemptions',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertSame($stock, $product->fresh()->quantity);
        $this->assertEquals($cartState, $cart->fresh()->getAttributes());
        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'cart_id' => $cart->id,
            'quantity' => 2,
        ]);
        Event::assertNotDispatched(OrderCreated::class);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_guest_cannot_checkout_a_user_owned_cart(): void
    {
        $owner = User::factory()->create();
        $cart = $this->cart('guest-protected-checkout', $owner);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame($owner->id, $cart->fresh()->user_id);
    }

    public function test_shipping_uses_session_authority_and_cart_id_only_as_an_assertion(): void
    {
        $this->seed();

        $product = Product::factory()->create();
        $cart = $this->cart('shipping-authority');
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 600,
            'total_price' => 600,
        ]);
        $payload = $this->shippingPayload();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $payload)
            ->assertOk()
            ->assertJsonPath('data.shipping_price', '0.00');

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $payload + ['cart_id' => $cart->id])
            ->assertOk()
            ->assertJsonPath('data.shipping_price', '0.00');

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $payload + ['cart_id' => $cart->id + 1000])
            ->assertUnprocessable()
            ->assertJsonPath('error.message', 'Cart session does not match the requested cart.');

        $this->withoutHeader('X-Cart-Session');

        $existingIdResponse = $this->postJson(
            '/api/v1/shipping/calculate',
            $payload + ['cart_id' => $cart->id],
        )->assertUnprocessable();
        $missingIdResponse = $this->postJson(
            '/api/v1/shipping/calculate',
            $payload + ['cart_id' => $cart->id + 1000],
        )->assertUnprocessable();

        $this->assertSame($existingIdResponse->getContent(), $missingIdResponse->getContent());
        $this->assertSame(
            'Cart session is required for cart-based shipping calculation.',
            $existingIdResponse->json('error.message'),
        );
    }

    public function test_shipping_rejects_guest_and_cross_user_access_without_cart_mutation(): void
    {
        $this->seed();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $cart = $this->cart('owned-shipping', $owner, 'KEEP');
        $state = $cart->getAttributes();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $this->shippingPayload())
            ->assertForbidden();

        $this->actingAs($other, 'sanctum')
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $this->shippingPayload())
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', $this->shippingPayload())
            ->assertOk();

        $this->assertEquals($state, $cart->fresh()->getAttributes());
    }

    public function test_quote_and_pc_builder_cart_consumers_use_the_shared_boundary(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $cart = $this->cart('secondary-consumers', $owner);

        $this->actingAs($other, 'sanctum')
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/cart/request-quote', ['notes' => 'No access'])
            ->assertForbidden();

        $this->assertDatabaseCount('quote_requests', 0);

        Auth::forgetGuards();
        $buildSession = $this->cartSession('pc-build-session');
        $build = PcBuild::query()->create([
            'session_id' => $buildSession,
            'name' => 'Protected build',
            'status' => 'draft',
        ]);

        $this->withHeaders([
            'X-PC-Build-Session' => $buildSession,
            'X-Cart-Session' => $cart->session_id,
        ])->postJson("/api/v1/pc-builder/builds/{$build->id}/add-to-cart")
            ->assertForbidden();

        $this->assertSame('draft', $build->fresh()->status);
    }

    private function cart(
        string $name,
        ?User $user = null,
        ?string $coupon = null,
    ): Cart {
        return Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'user_id' => $user?->id,
            'customer_email' => $user?->email,
            'coupon_code' => $coupon,
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
    }

    private function bundle(): ProductBundle
    {
        return ProductBundle::query()->create([
            'name' => 'Ownership Test Bundle',
            'slug' => 'ownership-test-bundle-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'type' => 'fixed_bundle',
            'pricing_type' => 'fixed_price',
            'fixed_price' => 25,
        ]);
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'checkout@example.test',
            'phone' => '0888123456',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'terms' => true,
        ];
    }

    private function shippingPayload(): array
    {
        return [
            'provider' => 'speedy',
            'delivery_type' => 'address',
            'shipping_method' => 'address',
            'city' => 'Sofia',
            'address' => 'bul. Bulgaria 1',
        ];
    }
}
