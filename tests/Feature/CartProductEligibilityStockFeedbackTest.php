<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\Cart;
use App\Models\Product;
use App\Services\Cart\CartLifecycleService;
use App\Services\Cart\CartReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CartProductEligibilityStockFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_tracked_add_succeeds_below_and_exactly_at_available_stock(): void
    {
        foreach ([2, 3] as $quantity) {
            $product = $this->product(['quantity' => 3]);

            $this->withHeader('X-Cart-Session', $this->cartSession("tracked-add-{$quantity}"))
                ->postJson('/api/v1/cart/items', [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ])
                ->assertOk()
                ->assertJsonPath('data.items.0.quantity', $quantity)
                ->assertJsonPath('data.items.0.readiness.stock.available_quantity', 3)
                ->assertJsonPath('data.items.0.readiness.stock.is_sufficient', true);
        }
    }

    public function test_stock_tracked_add_rejects_unavailable_quantity_without_partial_mutation(): void
    {
        Queue::fake();
        $product = $this->product(['quantity' => 3]);
        $session = $this->cartSession('tracked-add');

        Queue::fake();

        $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 4])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_quantity_unavailable')
            ->assertJsonPath('error.details.product_id', $product->id)
            ->assertJsonPath('error.details.requested_quantity', 4)
            ->assertJsonPath('error.details.available_quantity', 3)
            ->assertJsonPath('error.details.max_purchasable_quantity', 3)
            ->assertJsonPath('error.details.issues.0.code', 'insufficient_stock');

        $cart = Cart::query()->where('session_id', $session)->firstOrFail();
        $this->assertSame(0, $cart->items()->count());
        $this->assertSame(3, $product->fresh()->quantity);
        Queue::assertNothingPushed();
    }

    public function test_add_validates_combined_existing_quantity_without_clamping(): void
    {
        Queue::fake();
        $product = $this->product(['quantity' => 3]);
        $cart = $this->cartWithItem($product, 2, 'combined-add');
        $item = $cart->items()->firstOrFail();
        $giftProduct = $this->product(['quantity' => 10]);
        $gift = $cart->items()->create([
            'product_id' => $giftProduct->id,
            'quantity' => 1,
            'is_gift' => true,
            'unit_price' => 0,
            'total_price' => 0,
        ]);
        $snapshot = $item->only(['quantity', 'unit_price', 'total_price', 'updated_at']);
        $giftSnapshot = $gift->only([
            'id',
            'product_id',
            'quantity',
            'is_gift',
            'promotion_id',
            'unit_price',
            'total_price',
        ]);

        Queue::fake();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_quantity_unavailable')
            ->assertJsonPath('error.details.requested_quantity', 4);

        $this->assertEquals($snapshot, $item->fresh()->only(array_keys($snapshot)));
        $this->assertSame($giftSnapshot, $gift->fresh()->only(array_keys($giftSnapshot)));
        $this->assertSame(2, $item->fresh()->quantity);
        $this->assertSame(3, $product->fresh()->quantity);
        Queue::assertNothingPushed();
    }

    public function test_stock_tracked_quantity_update_succeeds_below_and_exactly_at_stock(): void
    {
        $product = $this->product(['quantity' => 5]);
        $cart = $this->cartWithItem($product, 1, 'successful-updates');
        $item = $cart->items()->firstOrFail();

        foreach ([4, 5] as $quantity) {
            $this->withHeader('X-Cart-Session', $cart->session_id)
                ->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => $quantity])
                ->assertOk()
                ->assertJsonPath('data.items.0.quantity', $quantity)
                ->assertJsonPath('data.items.0.readiness.stock.is_sufficient', true);
        }

        $this->assertSame(5, $item->fresh()->quantity);
        $this->assertSame(5, $product->fresh()->quantity);
    }

    public function test_quantity_update_rejects_stock_and_product_eligibility_changes_without_mutation(): void
    {
        $product = $this->product(['quantity' => 5]);
        $cart = $this->cartWithItem($product, 2, 'update-rejection');
        $cart->update(['coupon_code' => 'PRESERVE']);
        $item = $cart->items()->firstOrFail();
        $snapshot = $item->only(['quantity', 'unit_price', 'total_price', 'updated_at']);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 6])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_quantity_unavailable');

        $this->assertEquals($snapshot, $item->fresh()->only(array_keys($snapshot)));
        $this->assertSame('PRESERVE', $cart->fresh()->coupon_code);

        $product->update(['active' => false]);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 1])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_product_unavailable')
            ->assertJsonPath('error.details.issues.0.code', 'product_inactive');

        $this->assertEquals($snapshot, $item->fresh()->only(array_keys($snapshot)));
    }

    public function test_purchase_enabled_non_stock_tracked_products_ignore_product_quantity_but_keep_cart_limit(): void
    {
        foreach (['preorder', 'backorder'] as $code) {
            $status = $this->availability($code, allowPurchase: true, trackStock: false);
            $product = $this->product([
                'availability_status_id' => $status->id,
                'stock_status' => $code,
                'quantity' => 0,
            ]);
            $session = $this->cartSession($code.'-add');

            $this->withHeader('X-Cart-Session', $session)
                ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 99])
                ->assertOk()
                ->assertJsonPath('data.items.0.quantity', 99)
                ->assertJsonPath('data.items.0.readiness.stock.tracked', false)
                ->assertJsonPath('data.items.0.readiness.stock.available_quantity', null)
                ->assertJsonPath('data.items.0.readiness.stock.max_purchasable_quantity', 99)
                ->assertJsonPath('data.readiness.can_checkout', true);
        }
    }

    public function test_non_stock_tracked_product_can_be_updated_to_cart_maximum(): void
    {
        $status = $this->availability('supplier_order', allowPurchase: true, trackStock: false);
        $product = $this->product([
            'availability_status_id' => $status->id,
            'stock_status' => 'supplier_order',
            'quantity' => 0,
        ]);
        $session = $this->cartSession('non-tracked-update');

        $response = $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();
        $itemId = $response->json('data.items.0.id');

        $this->withHeader('X-Cart-Session', $session)
            ->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 99])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 99)
            ->assertJsonPath('data.items.0.readiness.stock.tracked', false)
            ->assertJsonPath('data.items.0.readiness.stock.max_purchasable_quantity', 99)
            ->assertJsonPath('data.items.0.readiness.stock.is_sufficient', true);
    }

    public function test_purchase_disabled_product_is_rejected_regardless_of_quantity(): void
    {
        $status = $this->availability('temporarily_unavailable', allowPurchase: false, trackStock: false);
        $product = $this->product([
            'availability_status_id' => $status->id,
            'quantity' => 50,
        ]);

        $this->withHeader('X-Cart-Session', $this->cartSession('purchase-disabled'))
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_product_unavailable')
            ->assertJsonPath('error.details.issues.0.code', 'product_purchase_disabled');
    }

    public function test_reserved_quantity_is_not_subtracted_from_current_available_stock(): void
    {
        $product = $this->product(['quantity' => 3, 'reserved_quantity' => 3]);

        $this->withHeader('X-Cart-Session', $this->cartSession('reserved-not-subtracted'))
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.items.0.readiness.stock.available_quantity', 3)
            ->assertJsonPath('data.items.0.readiness.stock.is_sufficient', true);
    }

    public function test_cart_view_flags_stale_line_without_clamping_deleting_or_writing_readiness(): void
    {
        $product = $this->product(['quantity' => 2]);
        $cart = $this->cartWithItem($product, 4, 'stale-display');
        $cart->update([
            'expires_at' => now()->addDays(CartLifecycleService::RENEWAL_THRESHOLD_DAYS + 1),
        ]);
        $item = $cart->items()->firstOrFail();
        $before = [
            'cart' => $cart->only(['status', 'coupon_code', 'updated_at']),
            'item' => $item->only(['quantity', 'unit_price', 'total_price', 'updated_at']),
            'product' => $product->only(['quantity', 'reserved_quantity', 'updated_at']),
        ];

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.items.0.readiness.is_eligible', true)
            ->assertJsonPath('data.items.0.readiness.can_checkout', false)
            ->assertJsonPath('data.items.0.readiness.issues.0.code', 'insufficient_stock')
            ->assertJsonPath('data.readiness.can_checkout', false)
            ->assertJsonPath('data.readiness.has_stock_issues', true);

        $this->assertEquals($before['cart'], $cart->fresh()->only(array_keys($before['cart'])));
        $this->assertEquals($before['item'], $item->fresh()->only(array_keys($before['item'])));
        $this->assertEquals($before['product'], $product->fresh()->only(array_keys($before['product'])));
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_soft_deleted_product_line_remains_visible_and_can_be_removed_or_cleared(): void
    {
        $product = $this->product(['quantity' => 5]);
        $cart = $this->cartWithItem($product, 1, 'soft-deleted-line');
        $item = $cart->items()->firstOrFail();
        $storedPrice = $item->unit_price;
        $product->delete();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.product', null)
            ->assertJsonPath('data.items.0.unit_price', $storedPrice)
            ->assertJsonPath('data.items.0.readiness.issues.0.code', 'product_deleted')
            ->assertJsonPath('data.readiness.can_checkout', false);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->deleteJson("/api/v1/cart/items/{$item->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $second = $this->product(['quantity' => 5]);
        $secondItem = $cart->items()->create([
            'product_id' => $second->id,
            'quantity' => 1,
            'unit_price' => $second->effectivePrice(),
            'total_price' => $second->effectivePrice(),
        ]);
        $second->delete();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->deleteJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->assertDatabaseMissing('cart_items', ['id' => $secondItem->id]);
    }

    public function test_unavailable_gift_stays_free_and_makes_cart_not_ready(): void
    {
        $paidProduct = $this->product(['quantity' => 5]);
        $giftProduct = $this->product(['quantity' => 5]);
        $cart = $this->cartWithItem($paidProduct, 1, 'unavailable-gift');
        $gift = $cart->items()->create([
            'product_id' => $giftProduct->id,
            'quantity' => 1,
            'is_gift' => true,
            'unit_price' => 0,
            'total_price' => 0,
        ]);
        $giftProduct->update(['active' => false]);

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.1.is_gift', true)
            ->assertJsonPath('data.items.1.unit_price', '0.00')
            ->assertJsonPath('data.items.1.total_price', '0.00')
            ->assertJsonPath('data.items.1.readiness.issues.0.code', 'product_inactive')
            ->assertJsonPath('data.readiness.can_checkout', false);

        $this->assertSame('0.00', $gift->fresh()->unit_price);
        $this->assertSame('0.00', $gift->fresh()->total_price);
    }

    public function test_product_issues_are_deterministic_and_do_not_expose_internal_costs(): void
    {
        $status = $this->availability('blocked', allowPurchase: false, trackStock: true);
        $product = $this->product([
            'availability_status_id' => $status->id,
            'active' => false,
            'published_at' => null,
            'workflow_status' => Product::WORKFLOW_DRAFT,
            'product_status' => 'draft',
            'slug' => '',
            'quantity' => 0,
            'purchase_price' => 12.34,
        ]);
        $product->category()->update(['is_active' => false]);
        $cart = $this->cartWithItem($product, 1, 'issue-order');

        $response = $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.readiness.can_checkout', false);

        $this->assertSame([
            'product_inactive',
            'product_unpublished',
            'product_status_inactive',
            'product_slug_missing',
            'product_category_unavailable',
            'product_purchase_disabled',
            'insufficient_stock',
        ], collect($response->json('data.items.0.readiness.issues'))->pluck('code')->all());
        $this->assertStringNotContainsString('purchase_price', $response->getContent());
        $this->assertStringNotContainsString('12.34', $response->getContent());
    }

    public function test_each_public_eligibility_failure_is_reported_without_mutating_the_line(): void
    {
        $cases = [
            'inactive' => [
                'code' => 'product_inactive',
                'mutate' => fn (Product $product) => $product->update(['active' => false]),
            ],
            'missing-published-at' => [
                'code' => 'product_unpublished',
                'mutate' => fn (Product $product) => $product->update(['published_at' => null]),
            ],
            'workflow-draft' => [
                'code' => 'product_unpublished',
                'mutate' => fn (Product $product) => $product->update([
                    'workflow_status' => Product::WORKFLOW_DRAFT,
                ]),
            ],
            'product-status-draft' => [
                'code' => 'product_status_inactive',
                'mutate' => fn (Product $product) => $product->update(['product_status' => 'draft']),
            ],
            'missing-slug' => [
                'code' => 'product_slug_missing',
                'mutate' => fn (Product $product) => $product->update(['slug' => '']),
            ],
            'inactive-category' => [
                'code' => 'product_category_unavailable',
                'mutate' => fn (Product $product) => $product->category()->update(['is_active' => false]),
            ],
            'deleted-category' => [
                'code' => 'product_category_unavailable',
                'mutate' => fn (Product $product) => $product->category()->firstOrFail()->delete(),
            ],
        ];

        foreach ($cases as $name => $case) {
            $product = $this->product(['quantity' => 5]);
            $cart = $this->cartWithItem($product, 1, 'eligibility-'.$name);
            $item = $cart->items()->firstOrFail();
            $case['mutate']($product);
            $itemSnapshot = $item->getAttributes();
            $productSnapshot = $product->fresh()->getAttributes();

            $response = $this->withHeader('X-Cart-Session', $cart->session_id)
                ->getJson('/api/v1/cart')
                ->assertOk()
                ->assertJsonPath('data.items.0.id', $item->id)
                ->assertJsonPath('data.items.0.quantity', 1)
                ->assertJsonPath('data.items.0.readiness.can_checkout', false)
                ->assertJsonPath('data.readiness.can_checkout', false);

            $this->assertContains(
                $case['code'],
                collect($response->json('data.items.0.readiness.issues'))->pluck('code')->all(),
                $name,
            );
            $this->assertEquals($itemSnapshot, $item->fresh()->getAttributes(), $name);
            $this->assertEquals($productSnapshot, $product->fresh()->getAttributes(), $name);
        }
    }

    public function test_readiness_service_distinguishes_missing_product_and_uses_bounded_queries(): void
    {
        $missing = app(CartReadinessService::class)->assessProduct(null, 999999, 1);
        $this->assertSame(['product_missing'], $missing->issueCodes());

        $cart = Cart::query()->create([
            'session_id' => $this->cartSession('bounded-queries'),
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);

        Product::factory()->count(12)->create(['quantity' => 5])->each(
            fn (Product $product) => $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => $product->effectivePrice(),
                'total_price' => $product->effectivePrice(),
            ]),
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(CartReadinessService::class)->assess($cart->fresh());
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertTrue($result->canCheckout);
        $this->assertLessThanOrEqual(10, $queryCount);
    }

    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'price' => 100,
            'regular_price' => 100,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'stock_status' => 'in_stock',
            'active' => true,
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'published_at' => now(),
        ], $overrides));
    }

    private function cartWithItem(Product $product, int $quantity, string $name): Cart
    {
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->effectivePrice(),
            'total_price' => $product->effectivePrice() * $quantity,
        ]);

        return $cart;
    }

    private function availability(string $code, bool $allowPurchase, bool $trackStock): AvailabilityStatus
    {
        return AvailabilityStatus::query()->create([
            'code' => $code,
            'name' => str($code)->headline(),
            'allow_purchase' => $allowPurchase,
            'show_stock_quantity' => $trackStock,
            'is_active' => true,
        ]);
    }
}
