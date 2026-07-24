<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\AvailabilityStatus;
use App\Models\B2BCompany;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PcBuild;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\PromotionRedemption;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\Bundles\BundlePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CartReadinessCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_rejects_non_ready_cart_before_every_side_effect(): void
    {
        $this->seed();
        Event::fake([OrderCreated::class]);
        Mail::fake();
        Queue::fake();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update(['quantity' => 1]);
        $cart = $this->cartWithItem($product, 2, 'checkout-side-effects');
        $counts = [
            'customers' => Customer::query()->count(),
            'orders' => Order::query()->count(),
            'shipments' => \DB::table('order_shipments')->count(),
            'payments' => \DB::table('payment_transactions')->count(),
            'redemptions' => PromotionRedemption::query()->count(),
        ];

        Queue::fake();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_not_ready')
            ->assertJsonPath(
                'error.message',
                'Your cart contains unavailable items. Please review your cart and try again.',
            )
            ->assertJsonPath('error.details.readiness.can_checkout', false)
            ->assertJsonPath('error.details.readiness.has_stock_issues', true)
            ->assertJsonPath('error.details.readiness.items.0.readiness.issues.0.code', 'insufficient_stock');

        $this->assertSame($counts['customers'], Customer::query()->count());
        $this->assertSame($counts['orders'], Order::query()->count());
        $this->assertSame($counts['shipments'], \DB::table('order_shipments')->count());
        $this->assertSame($counts['payments'], \DB::table('payment_transactions')->count());
        $this->assertSame($counts['redemptions'], PromotionRedemption::query()->count());
        $this->assertSame(1, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(1, $cart->items()->count());
        Event::assertNotDispatched(OrderCreated::class);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_price_review_precedes_readiness_then_replenishment_allows_checkout(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update(['price' => 100, 'regular_price' => 100, 'quantity' => 1]);
        $cart = $this->cartWithItem($product, 2, 'price-before-readiness', storedPrice: 80);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_price_changed');

        $this->assertSame('100.00', $cart->items()->firstOrFail()->unit_price);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertSame(0, Order::query()->count());

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_not_ready');

        $product->update(['quantity' => 3]);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.readiness.can_checkout', true);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated();

        $this->assertSame('converted', $cart->fresh()->status);
        $this->assertSame(1, $product->fresh()->quantity);
    }

    public function test_empty_and_gift_only_carts_are_not_checkout_ready(): void
    {
        $empty = $this->cart('empty-cart');

        $this->withHeader('X-Cart-Session', $empty->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.readiness.can_checkout', false);

        $giftProduct = Product::factory()->create(['quantity' => 5]);
        $giftOnly = $this->cart('gift-only');
        $giftOnly->items()->create([
            'product_id' => $giftProduct->id,
            'quantity' => 1,
            'is_gift' => true,
            'unit_price' => 0,
            'total_price' => 0,
        ]);

        $this->withHeader('X-Cart-Session', $giftOnly->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '0.00')
            ->assertJsonPath('data.items.0.total_price', '0.00')
            ->assertJsonPath('data.readiness.can_checkout', false);
    }

    public function test_bundle_readiness_uses_component_quantity_times_cart_quantity(): void
    {
        $component = Product::factory()->create(['quantity' => 5, 'price' => 100, 'regular_price' => 100]);
        $bundle = ProductBundle::query()->create([
            'name' => 'Multiplied stock bundle',
            'slug' => 'multiplied-stock-bundle',
            'status' => 'active',
            'type' => 'fixed_bundle',
            'pricing_type' => 'fixed_price',
            'fixed_price' => 150,
        ]);
        $bundle->items()->create([
            'product_id' => $component->id,
            'component_group' => 'base',
            'is_required' => true,
            'quantity' => 2,
        ]);
        $selectedItems = app(BundlePricingService::class)->calculate($bundle)['selected_items'];
        $cart = $this->cart('bundle-readiness');
        $bundleItem = $cart->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => $selectedItems,
            'quantity' => 3,
            'unit_price' => 150,
            'total_price' => 450,
        ]);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.bundle_items.0.quantity', 3)
            ->assertJsonPath('data.bundle_items.0.unit_price', '150.00')
            ->assertJsonPath('data.bundle_items.0.readiness.issues.0.code', 'bundle_insufficient_stock')
            ->assertJsonPath('data.bundle_items.0.readiness.stock.components.0.requested_quantity', 6)
            ->assertJsonPath('data.bundle_items.0.readiness.stock.components.0.available_quantity', 5)
            ->assertJsonPath('data.bundle_items.0.readiness.stock.max_purchasable_quantity', 2)
            ->assertJsonPath('data.readiness.can_checkout', false);

        $this->assertSame(3, $bundleItem->fresh()->quantity);
        $this->assertSame('450.00', $bundleItem->fresh()->total_price);
        $this->assertSame(5, $component->fresh()->quantity);
    }

    public function test_bundle_readiness_reports_each_stale_state_without_changing_snapshot(): void
    {
        $component = Product::factory()->create(['quantity' => 10, 'price' => 100, 'regular_price' => 100]);
        $bundle = ProductBundle::query()->create([
            'name' => 'Stale state bundle',
            'slug' => 'stale-state-bundle',
            'status' => 'active',
            'type' => 'fixed_bundle',
            'pricing_type' => 'fixed_price',
            'fixed_price' => 80,
        ]);
        $bundle->items()->create([
            'product_id' => $component->id,
            'component_group' => 'base',
            'is_required' => true,
            'quantity' => 1,
        ]);
        $selectedItems = app(BundlePricingService::class)->calculate($bundle)['selected_items'];
        $cart = $this->cart('bundle-stale-states');
        $bundleItem = $cart->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => $selectedItems,
            'quantity' => 1,
            'unit_price' => 80,
            'total_price' => 80,
        ]);
        $snapshot = $bundleItem->only([
            'selected_items',
            'quantity',
            'unit_price',
            'total_price',
        ]);

        $this->assertBundleReadiness($cart, $bundleItem->id, null, true);

        $bundle->update(['status' => 'inactive']);
        $this->assertBundleReadiness($cart, $bundleItem->id, 'bundle_unavailable');
        $bundle->update(['status' => 'active', 'ends_at' => now()->subMinute()]);
        $this->assertBundleReadiness($cart, $bundleItem->id, 'bundle_unavailable');
        $bundle->update(['ends_at' => null]);

        $component->update(['active' => false]);
        $this->assertBundleReadiness($cart, $bundleItem->id, 'bundle_product_unavailable');
        $component->update(['active' => true]);
        $component->delete();
        $this->assertBundleReadiness($cart, $bundleItem->id, 'bundle_product_unavailable');
        $component->restore();

        $blocked = AvailabilityStatus::query()->create([
            'code' => 'bundle_blocked',
            'name' => 'Bundle blocked',
            'allow_purchase' => false,
            'show_stock_quantity' => false,
            'is_active' => true,
        ]);
        $component->update(['availability_status_id' => $blocked->id]);
        $this->assertBundleReadiness($cart, $bundleItem->id, 'bundle_product_unavailable');

        $this->assertEquals(
            $snapshot,
            $bundleItem->fresh()->only(array_keys($snapshot)),
        );
    }

    public function test_shipping_and_cart_quote_reject_non_ready_cart_without_partial_quote(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update(['quantity' => 1]);
        $cart = $this->cartWithItem($product, 2, 'shipping-quote-readiness');

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/shipping/calculate', [
                'provider' => 'speedy',
                'delivery_type' => 'address',
                'shipping_method' => 'address',
                'city' => 'Sofia',
                'address' => 'Address',
                'cart_id' => $cart->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_not_ready');

        $user = $this->b2bUser();
        $quoteCount = QuoteRequest::query()->count();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/cart/request-quote', ['notes' => 'Not ready'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_not_ready');

        $this->assertSame($quoteCount, QuoteRequest::query()->count());
    }

    public function test_pc_builder_rejects_all_components_atomically_when_one_is_unavailable(): void
    {
        $buildSession = $this->cartSession('atomic-build-session');
        $cartSession = $this->cartSession('atomic-build-cart');
        $build = PcBuild::query()->create([
            'session_id' => $buildSession,
            'name' => 'Atomic build',
            'status' => 'draft',
        ]);
        $available = Product::factory()->create(['quantity' => 10]);
        $unavailable = Product::factory()->create(['quantity' => 10, 'active' => false]);
        $build->items()->create([
            'product_id' => $available->id,
            'component_type' => 'cpu',
            'quantity' => 1,
        ]);
        $build->items()->create([
            'product_id' => $unavailable->id,
            'component_type' => 'gpu',
            'quantity' => 1,
        ]);

        $this->withHeaders([
            'X-PC-Build-Session' => $buildSession,
            'X-Cart-Session' => $cartSession,
        ])
            ->postJson("/api/v1/pc-builder/builds/{$build->id}/add-to-cart")
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_product_unavailable');

        $cart = Cart::query()->where('session_id', $cartSession)->firstOrFail();
        $this->assertSame(0, $cart->items()->count());
        $this->assertSame('draft', $build->fresh()->status);
    }

    private function cart(string $name): Cart
    {
        return Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
    }

    private function cartWithItem(
        Product $product,
        int $quantity,
        string $name,
        ?float $storedPrice = null,
    ): Cart {
        $cart = $this->cart($name);
        $price = $storedPrice ?? $product->effectivePrice();
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'total_price' => $price * $quantity,
        ]);

        return $cart;
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'readiness@example.test',
            'phone' => '0888123456',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'payment_method' => 'cash_on_delivery',
            'shipping_provider' => 'manual',
            'shipping_method' => 'address',
            'delivery_type' => 'address',
            'city' => 'Sofia',
            'terms' => true,
        ];
    }

    private function b2bUser(): User
    {
        $user = User::factory()->create([
            'email' => 'readiness-b2b@example.test',
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'is_active' => true,
        ]);
        $company = B2BCompany::query()->create([
            'name' => 'Readiness B2B Ltd',
            'vat_number' => 'BG123456700',
            'email' => $user->email,
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
        $company->users()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        return $user;
    }

    private function assertBundleReadiness(
        Cart $cart,
        int $bundleItemId,
        ?string $issueCode,
        bool $canCheckout = false,
    ): void {
        $response = $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.bundle_items.0.id', $bundleItemId)
            ->assertJsonPath('data.bundle_items.0.readiness.can_checkout', $canCheckout)
            ->assertJsonPath('data.readiness.can_checkout', $canCheckout);

        if ($issueCode !== null) {
            $this->assertContains(
                $issueCode,
                collect($response->json('data.bundle_items.0.readiness.issues'))->pluck('code')->all(),
            );
        }
    }
}
