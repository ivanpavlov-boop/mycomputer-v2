<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductBundles\ProductBundleResource;
use App\Models\Cart;
use App\Models\MarketingEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Promotion;
use App\Models\RewardVoucher;
use App\Models\User;
use App\Services\Loyalty\PointsService;
use App\Services\Loyalty\RewardEngine;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductBundleTest extends TestCase
{
    use RefreshDatabase;

    protected Product $keyboard;

    protected Product $mouse;

    protected Product $headset;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();

        $this->keyboard = Product::factory()->create([
            'name' => 'Gaming Keyboard',
            'slug' => 'gaming-keyboard',
            'sku' => 'KB-BUNDLE',
            'price' => 120,
            'promo_price' => null,
            'quantity' => 10,
            'active' => true,
            'published_at' => now(),
            'stock_status' => 'in_stock',
        ]);
        $this->mouse = Product::factory()->create([
            'name' => 'Gaming Mouse',
            'slug' => 'gaming-mouse',
            'sku' => 'MS-BUNDLE',
            'price' => 80,
            'promo_price' => null,
            'quantity' => 10,
            'active' => true,
            'published_at' => now(),
            'stock_status' => 'in_stock',
        ]);
        $this->headset = Product::factory()->create([
            'name' => 'Gaming Headset',
            'slug' => 'gaming-headset',
            'sku' => 'HS-BUNDLE',
            'price' => 100,
            'promo_price' => null,
            'quantity' => 10,
            'active' => true,
            'published_at' => now(),
            'stock_status' => 'in_stock',
        ]);
    }

    public function test_bundle_index_and_detail_are_public(): void
    {
        $bundle = $this->fixedBundle();

        $this->getJson('/api/v1/bundles')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $bundle->slug);

        $this->getJson('/api/v1/bundles/'.$bundle->slug)
            ->assertOk()
            ->assertJsonPath('data.price', 169)
            ->assertJsonPath('data.savings', 31);
    }

    public function test_product_bundle_recommendations_endpoint(): void
    {
        $bundle = $this->fixedBundle();

        $this->getJson('/api/v1/products/'.$this->keyboard->slug.'/bundles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $bundle->id);
    }

    public function test_fixed_bundle_can_be_added_to_cart(): void
    {
        $bundle = $this->fixedBundle();

        $this->withHeader('X-Cart-Session', 'bundle-cart')
            ->postJson('/api/v1/cart/bundles', [
                'bundle_id' => $bundle->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.subtotal', 338)
            ->assertJsonPath('data.bundle_items.0.bundle_name', $bundle->name);
    }

    public function test_configurable_bundle_requires_valid_option_selection(): void
    {
        $bundle = $this->configurableBundle();

        $this->withHeader('X-Cart-Session', 'invalid-option-cart')
            ->postJson('/api/v1/cart/bundles', [
                'bundle_id' => $bundle->id,
                'quantity' => 1,
                'selected_items' => [
                    ['component_group' => 'mouse', 'product_id' => $this->keyboard->id],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_configurable_bundle_can_use_selected_option(): void
    {
        $bundle = $this->configurableBundle();

        $this->withHeader('X-Cart-Session', 'configurable-cart')
            ->postJson('/api/v1/cart/bundles', [
                'bundle_id' => $bundle->id,
                'quantity' => 1,
                'selected_items' => [
                    ['component_group' => 'mouse', 'product_id' => $this->mouse->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.subtotal', 200);
    }

    public function test_checkout_converts_bundle_to_order_and_reduces_stock(): void
    {
        $bundle = $this->fixedBundle();

        $this->withHeader('X-Cart-Session', 'bundle-checkout')
            ->postJson('/api/v1/cart/bundles', [
                'bundle_id' => $bundle->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $this->withHeader('X-Cart-Session', 'bundle-checkout')
            ->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.bundle_items.0.bundle_name', $bundle->name);

        $this->assertDatabaseHas('order_bundle_items', [
            'product_bundle_id' => $bundle->id,
            'quantity' => 2,
            'total_price' => 338,
        ]);
        $this->assertSame(8, $this->keyboard->fresh()->quantity);
        $this->assertSame(8, $this->mouse->fresh()->quantity);
    }

    public function test_bundle_promotion_rule_targets_bundle(): void
    {
        $bundle = $this->fixedBundle();
        $cart = Cart::query()->create(['session_id' => 'bundle-promo', 'status' => 'active']);

        $this->withHeader('X-Cart-Session', 'bundle-promo')
            ->postJson('/api/v1/cart/bundles', [
                'bundle_id' => $bundle->id,
                'quantity' => 1,
            ])
            ->assertOk();

        $promotion = Promotion::query()->create([
            'name' => 'Bundle extra discount',
            'type' => 'cart_discount',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'stackable' => true,
        ]);
        $promotion->rules()->create(['rule_type' => 'bundle_id', 'operator' => 'equals', 'value' => ['value' => $bundle->id]]);
        $promotion->actions()->create(['action_type' => 'fixed_discount', 'configuration' => ['amount' => 20]]);

        $result = app(PromotionEngineService::class)->evaluate($cart->fresh(['items.product', 'bundleItems.bundle']));

        $this->assertSame(20.0, $result['discount_total']);
    }

    public function test_bundle_promotion_rule_targets_bundle_type_product_and_brand(): void
    {
        $bundle = $this->fixedBundle();
        $cart = Cart::query()->create(['session_id' => 'bundle-rules', 'status' => 'active']);
        $this->withHeader('X-Cart-Session', 'bundle-rules')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $typePromotion = $this->bundlePromotion('Type promo', 'bundle_type', $bundle->type, 10);
        $productPromotion = $this->bundlePromotion('Product promo', 'bundle_contains_product', $this->keyboard->id, 15);
        $brandPromotion = $this->bundlePromotion('Brand promo', 'bundle_contains_brand', $this->keyboard->brand_id, 5);

        $typePromotion->update(['stackable' => true]);
        $productPromotion->update(['stackable' => true]);
        $brandPromotion->update(['stackable' => true]);

        $result = app(PromotionEngineService::class)->evaluate($cart->fresh(['items.product', 'bundleItems.bundle']));

        $this->assertSame(30.0, $result['discount_total']);
    }

    public function test_invalid_bundle_promotion_rule_does_not_apply(): void
    {
        $bundle = $this->fixedBundle();
        $cart = Cart::query()->create(['session_id' => 'invalid-bundle-rule', 'status' => 'active']);
        $this->withHeader('X-Cart-Session', 'invalid-bundle-rule')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $this->bundlePromotion('Wrong bundle promo', 'bundle_id', 999999, 20);

        $result = app(PromotionEngineService::class)->evaluate($cart->fresh(['items.product', 'bundleItems.bundle']));

        $this->assertSame(0.0, $result['discount_total']);
    }

    public function test_non_stackable_bundle_promotions_keep_best_discount(): void
    {
        $bundle = $this->fixedBundle();
        $cart = Cart::query()->create(['session_id' => 'bundle-stacking', 'status' => 'active']);
        $this->withHeader('X-Cart-Session', 'bundle-stacking')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $this->bundlePromotion('Small bundle promo', 'bundle_id', $bundle->id, 10, priority: 1, stackable: false);
        $this->bundlePromotion('Large bundle promo', 'bundle_id', $bundle->id, 25, priority: 2, stackable: false);

        $result = app(PromotionEngineService::class)->evaluate($cart->fresh(['items.product', 'bundleItems.bundle']));

        $this->assertSame(25.0, $result['discount_total']);
        $this->assertCount(1, $result['applied_promotions']);
    }

    public function test_reward_voucher_applies_to_bundle_only_cart(): void
    {
        $user = User::factory()->create(['email' => 'bundle-loyalty@example.com']);
        app(PointsService::class)->earn($user, 500, 'Opening balance.');
        $voucher = $this->rewardVoucher('BUNDLE50', points: 100, discount: 50);
        $bundle = $this->fixedBundle();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', 'bundle-loyalty')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', 'bundle-loyalty')
            ->postJson('/api/v1/checkout', array_merge($this->checkoutPayload(), [
                'email' => 'bundle-loyalty@example.com',
                'reward_code' => $voucher->code,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.discount_total', '50.00');

        $this->assertSame(400, $user->loyaltyAccount()->firstOrFail()->points_balance);
    }

    public function test_loyalty_points_are_earned_from_bundle_checkout(): void
    {
        $user = User::factory()->create(['email' => 'bundle-points@example.com']);
        $bundle = $this->fixedBundle();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', 'bundle-points')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', 'bundle-points')
            ->postJson('/api/v1/checkout', array_merge($this->checkoutPayload(), ['email' => 'bundle-points@example.com']))
            ->assertCreated();

        $order = Order::query()->where('customer_email', 'bundle-points@example.com')->firstOrFail();
        $order->update(['status' => 'completed', 'payment_status' => 'paid']);
        $points = app(RewardEngine::class)->awardForCompletedOrder($order->fresh('user.loyaltyAccount'));

        $this->assertGreaterThan(0, $points);
        $this->assertSame($points, $user->loyaltyAccount()->firstOrFail()->points_balance);
    }

    public function test_loyalty_voucher_does_not_create_negative_total_with_bundle_discounts(): void
    {
        $user = User::factory()->create(['email' => 'bundle-negative@example.com']);
        app(PointsService::class)->earn($user, 500, 'Opening balance.');
        $voucher = $this->rewardVoucher('BUNDLE100', points: 100, discount: 100);
        $bundle = $this->fixedBundle();
        $this->bundlePromotion('Almost free bundle', 'bundle_id', $bundle->id, 160, stackable: true);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', 'bundle-negative')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', 'bundle-negative')
            ->postJson('/api/v1/checkout', array_merge($this->checkoutPayload(), [
                'email' => 'bundle-negative@example.com',
                'reward_code' => $voucher->code,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.grand_total', '0.00');
    }

    public function test_bundle_analytics_events_include_required_payload(): void
    {
        $bundle = $this->fixedBundle();

        $this->withHeader('X-Cart-Session', 'bundle-analytics')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $added = MarketingEvent::query()->where('event_name', 'bundle_added_to_cart')->firstOrFail();
        $this->assertSame($bundle->id, $added->payload['bundle_id']);
        $this->assertSame($bundle->name, $added->payload['bundle_name']);
        $this->assertSame(169, $added->payload['total_price']);

        $this->withHeader('X-Cart-Session', 'bundle-analytics')
            ->postJson('/api/v1/checkout', array_merge($this->checkoutPayload(), ['email' => 'bundle-analytics@example.com']))
            ->assertCreated();

        $purchased = MarketingEvent::query()->where('event_name', 'bundle_purchased')->firstOrFail();
        $this->assertSame($bundle->id, $purchased->payload['bundle_id']);
        $this->assertSame($bundle->name, $purchased->payload['bundle_name']);
        $this->assertSame('169.00', $purchased->payload['total_price']);
    }

    public function test_bundle_cart_item_cannot_be_updated_or_deleted_from_another_session(): void
    {
        $bundle = $this->fixedBundle();
        $response = $this->withHeader('X-Cart-Session', 'owner-session')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $bundle->id, 'quantity' => 1])
            ->assertOk();

        $bundleItemId = $response->json('data.bundle_items.0.id');

        $this->withHeader('X-Cart-Session', 'other-session')
            ->patchJson('/api/v1/cart/bundles/'.$bundleItemId, ['quantity' => 2])
            ->assertNotFound();

        $this->withHeader('X-Cart-Session', 'other-session')
            ->deleteJson('/api/v1/cart/bundles/'.$bundleItemId)
            ->assertNotFound();
    }

    public function test_inactive_and_expired_bundles_cannot_be_added_to_cart(): void
    {
        $inactive = $this->fixedBundle();
        $inactive->update(['status' => 'inactive']);
        $expired = $this->fixedBundle('expired-pack');
        $expired->update(['ends_at' => now()->subMinute()]);

        $this->withHeader('X-Cart-Session', 'inactive-bundle')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $inactive->id, 'quantity' => 1])
            ->assertUnprocessable();

        $this->withHeader('X-Cart-Session', 'expired-bundle')
            ->postJson('/api/v1/cart/bundles', ['bundle_id' => $expired->id, 'quantity' => 1])
            ->assertUnprocessable();
    }

    public function test_product_bundle_filament_resource_access_and_urls(): void
    {
        Permission::findOrCreate('manage products', 'web');
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('manage products');

        $this->actingAs($manager);

        $this->assertTrue(ProductBundleResource::canViewAny());
        $this->get(ProductBundleResource::getUrl('index'))->assertOk();
        $this->get(ProductBundleResource::getUrl('create'))->assertOk();

        $bundle = $this->fixedBundle();
        $this->get(ProductBundleResource::getUrl('edit', ['record' => $bundle]))->assertOk();
    }

    private function fixedBundle(string $slug = 'keyboard-and-mouse-pack'): ProductBundle
    {
        $bundle = ProductBundle::query()->create([
            'name' => str($slug)->replace('-', ' ')->headline()->toString(),
            'slug' => $slug,
            'status' => 'active',
            'type' => 'fixed_bundle',
            'pricing_type' => 'fixed_price',
            'fixed_price' => 169,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        $bundle->items()->create(['product_id' => $this->keyboard->id, 'component_group' => 'keyboard', 'quantity' => 1, 'sort_order' => 1]);
        $bundle->items()->create(['product_id' => $this->mouse->id, 'component_group' => 'mouse', 'quantity' => 1, 'sort_order' => 2]);

        return $bundle->fresh(['items.product', 'options.product']);
    }

    private function configurableBundle(): ProductBundle
    {
        $bundle = ProductBundle::query()->create([
            'name' => 'Gaming Starter Pack',
            'slug' => 'gaming-starter-pack',
            'status' => 'active',
            'type' => 'configurable_bundle',
            'pricing_type' => 'discount_fixed',
            'discount_value' => 20,
        ]);
        $bundle->items()->create(['product_id' => $this->keyboard->id, 'component_group' => 'keyboard', 'quantity' => 1, 'sort_order' => 1]);
        $bundle->items()->create(['component_group' => 'mouse', 'is_required' => true, 'quantity' => 1, 'sort_order' => 2]);
        $bundle->options()->create(['component_group' => 'mouse', 'product_id' => $this->mouse->id, 'is_default' => true, 'price_adjustment' => 20]);
        $bundle->options()->create(['component_group' => 'mouse', 'product_id' => $this->headset->id, 'is_default' => false, 'price_adjustment' => 40]);

        return $bundle->fresh(['items.product', 'options.product']);
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'bundle@example.com',
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

    private function bundlePromotion(string $name, string $ruleType, mixed $value, float $amount, int $priority = 0, bool $stackable = true): Promotion
    {
        $promotion = Promotion::query()->create([
            'name' => $name,
            'type' => 'cart_discount',
            'status' => 'active',
            'priority' => $priority,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'stackable' => $stackable,
        ]);
        $promotion->rules()->create(['rule_type' => $ruleType, 'operator' => 'equals', 'value' => ['value' => $value]]);
        $promotion->actions()->create(['action_type' => 'fixed_discount', 'configuration' => ['amount' => $amount]]);

        return $promotion->fresh(['rules', 'actions']);
    }

    private function rewardVoucher(string $code, int $points, int $discount): RewardVoucher
    {
        return RewardVoucher::query()->create([
            'code' => $code,
            'title' => $code,
            'points_cost' => $points,
            'discount_type' => 'fixed',
            'discount_value' => $discount,
            'minimum_order_amount' => null,
            'usage_limit' => null,
            'usage_count' => 0,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
    }
}
