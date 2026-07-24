<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Exceptions\CartMergeConflictException;
use App\Models\Cart;
use App\Models\CartBundleItem;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\User;
use App\Services\Cart\CartLifecycleService;
use App\Services\Promotions\PromotionEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CartLifecyclePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-24 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_guest_and_authenticated_missing_or_unknown_sessions_follow_the_policy(): void
    {
        $lifecycle = app(CartLifecycleService::class);

        $missingGuest = $lifecycle->resolveGuest(null);

        $this->assertSame(CartStatus::Active->value, $missingGuest->status);
        $this->assertNull($missingGuest->user_id);
        $this->assertSame(now()->addDays(14)->toDateTimeString(), $missingGuest->expires_at?->toDateTimeString());

        $unknownSession = $this->cartSession('unknown-guest-session');
        $unknownGuest = $lifecycle->resolveGuest($unknownSession);

        $this->assertSame($unknownSession, $unknownGuest->session_id);
        $this->assertNull($unknownGuest->user_id);

        $user = User::factory()->create();
        $existing = $this->cart('existing-user-cart', [
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'expires_at' => now()->addDays(10),
        ]);
        $countBefore = Cart::query()->count();

        $this->assertTrue($existing->is($lifecycle->resolveAuthenticated($user, null)));
        $this->assertTrue($existing->is(
            $lifecycle->resolveAuthenticated($user, $this->cartSession('unknown-auth-session')),
        ));
        $this->assertSame($countBefore, Cart::query()->count());

        $newUser = User::factory()->create();
        $owned = $lifecycle->resolveAuthenticated($newUser, null);

        $this->assertSame($newUser->id, $owned->user_id);
        $this->assertSame($newUser->email, $owned->customer_email);

        $thirdUser = User::factory()->create();
        $supplied = $this->cartSession('unknown-new-user-session');
        $createdFromSupplied = $lifecycle->resolveAuthenticated($thirdUser, $supplied);

        $this->assertSame($supplied, $createdFromSupplied->session_id);
        $this->assertSame($thirdUser->id, $createdFromSupplied->user_id);
    }

    public function test_expiry_is_renewed_only_at_the_approved_threshold(): void
    {
        $lifecycle = app(CartLifecycleService::class);
        $longExpiry = $this->cart('long-expiry', ['expires_at' => now()->addDays(8)]);
        $thresholdExpiry = $this->cart('threshold-expiry', ['expires_at' => now()->addDays(7)]);
        $nullExpiry = $this->cart('null-expiry', ['expires_at' => null]);

        $longUpdatedAt = $longExpiry->updated_at;

        $lifecycle->resolveGuest($longExpiry->session_id);
        $lifecycle->resolveGuest($thresholdExpiry->session_id);
        $lifecycle->resolveGuest($nullExpiry->session_id);

        $this->assertSame(
            now()->addDays(8)->toDateTimeString(),
            $longExpiry->fresh()->expires_at?->toDateTimeString(),
        );
        $this->assertTrue($longExpiry->fresh()->updated_at->equalTo($longUpdatedAt));
        $this->assertSame(
            now()->addDays(14)->toDateTimeString(),
            $thresholdExpiry->fresh()->expires_at?->toDateTimeString(),
        );
        $this->assertSame(
            now()->addDays(14)->toDateTimeString(),
            $nullExpiry->fresh()->expires_at?->toDateTimeString(),
        );
    }

    public function test_guest_historical_sessions_rotate_without_reactivating_history(): void
    {
        $lifecycle = app(CartLifecycleService::class);
        $pastActive = $this->cart('past-active', ['expires_at' => now()]);
        $converted = $this->cart('converted-history', ['status' => CartStatus::Converted->value]);
        $merged = $this->cart('merged-history', ['status' => CartStatus::Merged->value]);
        $expired = $this->cart('expired-history', ['status' => CartStatus::Expired->value]);

        foreach ([$pastActive, $converted, $merged, $expired] as $historical) {
            $replacement = $lifecycle->resolveGuest($historical->session_id);

            $this->assertNotSame($historical->session_id, $replacement->session_id);
            $this->assertSame(CartStatus::Active->value, $replacement->status);
            $this->assertNull($replacement->user_id);
        }

        $this->assertSame(CartStatus::Expired->value, $pastActive->fresh()->status);
        $this->assertSame(CartStatus::Converted->value, $converted->fresh()->status);
        $this->assertSame(CartStatus::Merged->value, $merged->fresh()->status);
        $this->assertSame(CartStatus::Expired->value, $expired->fresh()->status);
    }

    public function test_authenticated_historical_sessions_resolve_the_canonical_cart(): void
    {
        $user = User::factory()->create();
        $canonical = $this->cart('canonical-history-target', [
            'user_id' => $user->id,
            'expires_at' => now()->addDays(10),
        ]);
        $pastActive = $this->cart('past-user-cart', [
            'user_id' => $user->id,
            'expires_at' => now(),
        ]);
        $converted = $this->cart('converted-user-cart', [
            'user_id' => $user->id,
            'status' => CartStatus::Converted->value,
        ]);
        $merged = $this->cart('merged-user-cart', [
            'user_id' => $user->id,
            'status' => CartStatus::Merged->value,
        ]);

        $lifecycle = app(CartLifecycleService::class);

        foreach ([$pastActive, $converted, $merged] as $historical) {
            $this->assertTrue($canonical->is(
                $lifecycle->resolveAuthenticated($user, $historical->session_id),
            ));
        }

        $this->assertSame(CartStatus::Expired->value, $pastActive->fresh()->status);
        $this->assertSame(CartStatus::Converted->value, $converted->fresh()->status);
        $this->assertSame(CartStatus::Merged->value, $merged->fresh()->status);
    }

    public function test_foreign_historical_carts_are_forbidden_without_mutation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $lifecycle = app(CartLifecycleService::class);

        foreach ([
            CartStatus::Active->value,
            CartStatus::Expired->value,
            CartStatus::Converted->value,
            CartStatus::Merged->value,
        ] as $status) {
            $cart = $this->cart('foreign-'.$status, [
                'user_id' => $owner->id,
                'status' => $status,
                'expires_at' => $status === CartStatus::Active->value ? now() : now()->subDay(),
            ]);
            $snapshot = $cart->fresh()->getAttributes();

            try {
                $lifecycle->resolveAuthenticated($other, $cart->session_id);
                $this->fail('Expected foreign authenticated cart access to fail.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }

            $this->assertEquals($snapshot, $cart->fresh()->getAttributes());

            try {
                $lifecycle->resolveGuest($cart->session_id);
                $this->fail('Expected foreign guest cart access to fail.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }

            $this->assertEquals($snapshot, $cart->fresh()->getAttributes());
        }
    }

    public function test_guest_cart_is_canonical_and_merges_user_items_bundles_coupon_and_gifts_once(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $firstProduct = Product::factory()->create();
        $secondProduct = Product::factory()->create();
        $targetGift = Product::factory()->create();
        $sourceGift = Product::factory()->create();
        $bundle = $this->bundle();
        $target = $this->cart('guest-merge-target', [
            'customer_email' => null,
            'expires_at' => now()->addDays(3),
        ]);
        $source = $this->cart('user-merge-source', [
            'user_id' => $user->id,
            'customer_email' => 'source@example.test',
            'coupon_code' => 'SAVE10',
        ]);
        $targetLine = $target->items()->create([
            'product_id' => $firstProduct->id,
            'quantity' => 3,
            'unit_price' => 10,
            'total_price' => 30,
        ]);
        $source->items()->create([
            'product_id' => $firstProduct->id,
            'quantity' => 2,
            'unit_price' => 99,
            'total_price' => 198,
        ]);
        $movedLine = $source->items()->create([
            'product_id' => $secondProduct->id,
            'quantity' => 4,
            'unit_price' => 25,
            'total_price' => 100,
        ]);
        $target->items()->create([
            'product_id' => $targetGift->id,
            'quantity' => 1,
            'is_gift' => true,
            'unit_price' => 0,
            'total_price' => 0,
        ]);
        $source->items()->create([
            'product_id' => $sourceGift->id,
            'quantity' => 1,
            'is_gift' => true,
            'unit_price' => 0,
            'total_price' => 0,
        ]);
        $sourceBundle = $source->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => [['component_group' => 'base', 'product_id' => $firstProduct->id]],
            'quantity' => 2,
            'unit_price' => 75,
            'total_price' => 150,
        ]);
        $targetBundle = $target->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => [['component_group' => 'base', 'product_id' => $secondProduct->id]],
            'quantity' => 1,
            'unit_price' => 65,
            'total_price' => 65,
        ]);
        $productSnapshots = Product::query()
            ->whereKey([$firstProduct->id, $secondProduct->id, $targetGift->id, $sourceGift->id])
            ->orderBy('id')
            ->get()
            ->map->getAttributes()
            ->all();

        $promotions = Mockery::mock(PromotionEngineService::class);
        $promotions->shouldReceive('applyAutomaticGifts')
            ->once()
            ->andReturnUsing(
                fn (Cart $cart): Cart => $cart->fresh(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']),
            );
        $this->app->instance(PromotionEngineService::class, $promotions);

        $resolved = app(CartLifecycleService::class)
            ->resolveAuthenticated($user, $target->session_id);

        $this->assertSame($target->id, $resolved->id);
        $this->assertSame($target->session_id, $resolved->session_id);
        $this->assertSame($user->id, $resolved->user_id);
        $this->assertSame($user->email, $resolved->customer_email);
        $this->assertSame('SAVE10', $resolved->coupon_code);
        $this->assertSame(now()->addDays(14)->toDateTimeString(), $resolved->expires_at?->toDateTimeString());

        $retained = $targetLine->fresh();
        $this->assertSame(5, $retained->quantity);
        $this->assertSame('10.00', $retained->unit_price);
        $this->assertSame('50.00', $retained->total_price);
        $this->assertSame($target->id, $movedLine->fresh()->cart_id);
        $this->assertSame('25.00', $movedLine->fresh()->unit_price);
        $this->assertSame('100.00', $movedLine->fresh()->total_price);
        $this->assertSame($target->id, $sourceBundle->fresh()->cart_id);
        $this->assertSame(2, $sourceBundle->fresh()->quantity);
        $this->assertSame('75.00', $sourceBundle->fresh()->unit_price);
        $selectedItems = $sourceBundle->fresh()->selected_items;
        $this->assertIsArray($selectedItems);
        $this->assertCount(1, $selectedItems);
        $this->assertIsArray($selectedItems[0]);
        $this->assertArrayHasKey('component_group', $selectedItems[0]);
        $this->assertArrayHasKey('product_id', $selectedItems[0]);
        $this->assertSame('base', $selectedItems[0]['component_group']);
        $this->assertSame($firstProduct->id, $selectedItems[0]['product_id']);
        $this->assertSame($target->id, $targetBundle->fresh()->cart_id);
        $this->assertSame(2, $target->bundleItems()->count());
        $this->assertFalse($target->items()->where('is_gift', true)->exists());

        $this->assertSame(CartStatus::Merged->value, $source->fresh()->status);
        $this->assertSame(now()->toDateTimeString(), $source->fresh()->expires_at?->toDateTimeString());
        $this->assertSame(0, $source->items()->count());
        $this->assertSame(0, $source->bundleItems()->count());
        $this->assertDatabaseHas('carts', ['id' => $source->id, 'session_id' => $source->session_id]);
        $this->assertSame(
            $productSnapshots,
            Product::query()
                ->whereKey([$firstProduct->id, $secondProduct->id, $targetGift->id, $sourceGift->id])
                ->orderBy('id')
                ->get()
                ->map->getAttributes()
                ->all(),
        );
    }

    public function test_multiple_user_carts_converge_deterministically_and_repeated_resolution_is_idempotent(): void
    {
        $user = User::factory()->create();
        $lowest = $this->cart('lowest-user-cart', ['user_id' => $user->id]);
        $second = $this->cart('second-user-cart', ['user_id' => $user->id]);
        $third = $this->cart('third-user-cart', ['user_id' => $user->id]);
        $expired = $this->cart('ignored-expired-user-cart', [
            'user_id' => $user->id,
            'status' => CartStatus::Expired->value,
        ]);
        $converted = $this->cart('ignored-converted-user-cart', [
            'user_id' => $user->id,
            'status' => CartStatus::Converted->value,
        ]);

        $lifecycle = app(CartLifecycleService::class);
        $resolved = $lifecycle->resolveAuthenticated($user, null);

        $this->assertSame($lowest->id, $resolved->id);
        $this->assertSame(CartStatus::Merged->value, $second->fresh()->status);
        $this->assertSame(CartStatus::Merged->value, $third->fresh()->status);
        $this->assertSame(CartStatus::Expired->value, $expired->fresh()->status);
        $this->assertSame(CartStatus::Converted->value, $converted->fresh()->status);
        $this->assertSame(1, $this->eligibleUserCarts($user)->count());
        $this->assertSame($lowest->id, $lifecycle->resolveAuthenticated($user, null)->id);
        $this->assertSame($lowest->id, $lifecycle->resolveAuthenticated($user, $second->session_id)->id);
        $this->assertSame(1, $this->eligibleUserCarts($user)->count());
    }

    public function test_supplied_same_user_cart_is_the_canonical_target(): void
    {
        $user = User::factory()->create();
        $lowest = $this->cart('same-user-lowest', ['user_id' => $user->id]);
        $supplied = $this->cart('same-user-supplied', ['user_id' => $user->id]);

        $resolved = app(CartLifecycleService::class)
            ->resolveAuthenticated($user, $supplied->session_id);

        $this->assertSame($supplied->id, $resolved->id);
        $this->assertSame(CartStatus::Merged->value, $lowest->fresh()->status);
        $this->assertSame(1, $this->eligibleUserCarts($user)->count());
    }

    public function test_quantity_conflict_returns_generic_409_and_rolls_back_every_change(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Sensitive product name']);
        $bundle = $this->bundle();
        $target = $this->cart('quantity-conflict-target', [
            'coupon_code' => 'SAVE10',
            'customer_email' => null,
        ]);
        $source = $this->cart('quantity-conflict-source', [
            'user_id' => $user->id,
            'coupon_code' => 'SAVE10',
        ]);
        $target->items()->create([
            'product_id' => $product->id,
            'quantity' => 60,
            'unit_price' => 10,
            'total_price' => 600,
        ]);
        $source->items()->create([
            'product_id' => $product->id,
            'quantity' => 40,
            'unit_price' => 20,
            'total_price' => 800,
        ]);
        $source->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => [],
            'quantity' => 1,
            'unit_price' => 50,
            'total_price' => 50,
        ]);
        $snapshot = $this->mergeSnapshot($target, $source);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', $target->session_id)
            ->getJson('/api/v1/cart')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'cart_merge_conflict')
            ->assertJsonPath('error.message', 'Cart merge requires review.');

        $response->assertDontSee($target->session_id)
            ->assertDontSee($source->session_id)
            ->assertDontSee('Sensitive product name')
            ->assertDontSee('SAVE10');
        $this->assertSame($snapshot, $this->mergeSnapshot($target, $source));
    }

    public function test_coupon_conflict_rolls_back_items_bundles_and_cart_state(): void
    {
        $user = User::factory()->create();
        $firstProduct = Product::factory()->create();
        $secondProduct = Product::factory()->create();
        $bundle = $this->bundle();
        $target = $this->cart('coupon-conflict-target', ['coupon_code' => 'FIRST']);
        $source = $this->cart('coupon-conflict-source', [
            'user_id' => $user->id,
            'coupon_code' => 'SECOND',
        ]);
        $target->items()->create([
            'product_id' => $firstProduct->id,
            'quantity' => 1,
            'unit_price' => 10,
            'total_price' => 10,
        ]);
        $source->items()->create([
            'product_id' => $secondProduct->id,
            'quantity' => 1,
            'unit_price' => 20,
            'total_price' => 20,
        ]);
        $source->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => [],
            'quantity' => 1,
            'unit_price' => 30,
            'total_price' => 30,
        ]);
        $snapshot = $this->mergeSnapshot($target, $source);

        try {
            app(CartLifecycleService::class)
                ->resolveAuthenticated($user, $target->session_id);
            $this->fail('Expected coupon merge conflict.');
        } catch (CartMergeConflictException $exception) {
            $this->assertSame('Cart merge requires review.', $exception->getMessage());
        }

        $this->assertSame($snapshot, $this->mergeSnapshot($target, $source));
    }

    public function test_non_empty_target_email_and_same_coupon_are_preserved(): void
    {
        $user = User::factory()->create(['email' => 'user@example.test']);
        $target = $this->cart('email-target', [
            'customer_email' => 'target@example.test',
            'coupon_code' => 'SHARED',
        ]);
        $source = $this->cart('email-source', [
            'user_id' => $user->id,
            'customer_email' => 'source@example.test',
            'coupon_code' => 'SHARED',
        ]);

        $resolved = app(CartLifecycleService::class)
            ->resolveAuthenticated($user, $target->session_id);

        $this->assertSame('target@example.test', $resolved->customer_email);
        $this->assertSame('SHARED', $resolved->coupon_code);
        $this->assertSame(CartStatus::Merged->value, $source->fresh()->status);
    }

    public function test_successful_checkout_converts_then_guest_resolution_rotates_the_session(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $sessionId = $this->cartSession('checkout-lifecycle-rotation');
        $stockBefore = $product->quantity;

        $this->withHeader('X-Cart-Session', $sessionId)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();

        $this->withHeader('X-Cart-Session', $sessionId)
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated();

        $this->assertDatabaseHas('carts', [
            'session_id' => $sessionId,
            'status' => CartStatus::Converted->value,
        ]);
        $this->assertSame($stockBefore - 1, $product->fresh()->quantity);

        $newSession = $this->withHeader('X-Cart-Session', $sessionId)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.status', CartStatus::Active->value)
            ->json('data.cart_session_id');

        $this->assertNotSame($sessionId, $newSession);
        $this->assertDatabaseHas('carts', [
            'session_id' => $sessionId,
            'status' => CartStatus::Converted->value,
        ]);
    }

    private function cart(string $seed, array $attributes = []): Cart
    {
        return Cart::query()->create(array_merge([
            'session_id' => $this->cartSession($seed),
            'status' => CartStatus::Active->value,
            'expires_at' => now()->addDays(10),
        ], $attributes));
    }

    private function bundle(): ProductBundle
    {
        return ProductBundle::query()->create([
            'name' => 'Lifecycle test bundle '.str()->random(8),
            'slug' => 'lifecycle-test-bundle-'.str()->random(8),
            'status' => 'active',
            'type' => 'fixed_bundle',
            'pricing_type' => 'fixed_price',
            'fixed_price' => 50,
        ]);
    }

    private function eligibleUserCarts(User $user)
    {
        return Cart::query()
            ->where('user_id', $user->id)
            ->where('status', CartStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
    }

    private function mergeSnapshot(Cart $target, Cart $source): array
    {
        return [
            'carts' => Cart::query()
                ->whereKey([$target->id, $source->id])
                ->orderBy('id')
                ->get()
                ->map->getAttributes()
                ->all(),
            'items' => CartItem::query()
                ->whereIn('cart_id', [$target->id, $source->id])
                ->orderBy('id')
                ->get()
                ->map->getAttributes()
                ->all(),
            'bundles' => CartBundleItem::query()
                ->whereIn('cart_id', [$target->id, $source->id])
                ->orderBy('id')
                ->get()
                ->map->getAttributes()
                ->all(),
        ];
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'lifecycle@example.test',
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
