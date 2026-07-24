<?php

namespace Tests\Feature;

use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductResource;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Services\Bundles\BundlePricingService;
use App\Services\Cart\CartPricingRefreshService;
use App\Services\Cart\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CartAuthoritativePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_resources_cart_and_active_sale_use_one_effective_eur_price(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00');

        try {
            $product = Product::factory()->create([
                'price' => 120.129,
                'regular_price' => 110.126,
                'promo_price' => 95,
                'sale_price' => 90.555,
                'sale_price_starts_at' => now()->subHour(),
                'sale_price_ends_at' => now()->addHour(),
            ]);

            $this->assertSame(90.56, $product->effectivePrice());
            $this->assertSame(90.56, app(CartService::class)->price($product));

            foreach ([ProductResource::make($product), ProductCardResource::make($product)] as $resource) {
                $payload = $resource->resolve(Request::create('/api/v1/products'));
                $this->assertSame('EUR', $payload['currency']);
                $this->assertSame(90.56, $payload['effective_price']);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_future_expired_and_invalid_sales_fall_back_to_regular_price(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00');

        try {
            $product = Product::factory()->make([
                'price' => 120,
                'regular_price' => 100,
                'sale_price' => 80,
                'sale_price_starts_at' => now()->addMinute(),
                'sale_price_ends_at' => null,
            ]);
            $this->assertSame(100.0, $product->effectivePrice());

            $product->sale_price_starts_at = null;
            $product->sale_price_ends_at = now()->subMinute();
            $this->assertSame(100.0, $product->effectivePrice());

            $product->sale_price_ends_at = null;
            $product->sale_price = 100;
            $this->assertSame(100.0, $product->effectivePrice());

            $product->sale_price = 101;
            $this->assertSame(100.0, $product->effectivePrice());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_cart_get_refreshes_stale_line_and_does_not_rewrite_an_unchanged_line(): void
    {
        $product = Product::factory()->create(['price' => 100, 'regular_price' => 100]);
        $cart = $this->cart('authoritative-display');
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 80,
            'total_price' => 160,
        ]);

        $response = $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.0.total_price', '200.00')
            ->assertJsonPath('data.subtotal', 200);

        $updatedAt = $item->fresh()->updated_at;
        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.subtotal', $response->json('data.subtotal'));

        $this->assertTrue($updatedAt->equalTo($item->fresh()->updated_at));
        $this->assertSame(2, $item->fresh()->quantity);
        $this->assertSame('100.00', $product->fresh()->regular_price);
    }

    public function test_gift_stays_zero_and_semantic_bundle_key_order_is_not_a_change(): void
    {
        $product = Product::factory()->create(['price' => 100, 'regular_price' => 90]);
        $secondProduct = Product::factory()->create(['price' => 50, 'regular_price' => 45]);
        $cart = $this->cart('semantic-bundle');
        $gift = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'is_gift' => true,
            'unit_price' => 12,
            'total_price' => 12,
        ]);
        $bundle = ProductBundle::query()->create([
            'name' => 'Authoritative bundle',
            'slug' => 'authoritative-bundle',
            'status' => 'active',
            'type' => 'fixed_bundle',
            'pricing_type' => 'fixed_price',
            'fixed_price' => 75,
        ]);
        $bundle->items()->create([
            'product_id' => $product->id,
            'component_group' => 'base',
            'is_required' => true,
            'quantity' => 1,
            'sort_order' => 1,
        ]);
        $bundle->items()->create([
            'product_id' => $secondProduct->id,
            'component_group' => 'secondary',
            'is_required' => true,
            'quantity' => 1,
            'sort_order' => 2,
        ]);
        $pricing = app(BundlePricingService::class)->calculate($bundle);
        $reordered = array_reverse(
            array_map(fn (array $line): array => array_reverse($line, true), $pricing['selected_items']),
        );
        $bundleItem = $cart->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => $reordered,
            'quantity' => 2,
            'unit_price' => 75,
            'total_price' => 150,
        ]);

        $result = app(CartPricingRefreshService::class)->refresh($cart);

        $this->assertTrue($result->changed);
        $this->assertSame([], $result->bundleChanges);
        $this->assertSame('0.00', $gift->fresh()->unit_price);
        $this->assertSame('0.00', $gift->fresh()->total_price);
        $this->assertEquals($reordered, $bundleItem->fresh()->selected_items);
        $this->assertSame(2, $bundleItem->fresh()->quantity);
    }

    private function cart(string $name): Cart
    {
        return Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
    }
}
