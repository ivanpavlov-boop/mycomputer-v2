<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductBundle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireStaleCartsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_and_reports_exact_counts(): void
    {
        $fixtures = $this->fixtures();
        $cartSnapshot = Cart::query()->orderBy('id')->get()->map->getAttributes()->all();
        $itemSnapshot = $fixtures['stale']->items()->firstOrFail()->getAttributes();
        $bundleSnapshot = $fixtures['stale']->bundleItems()->firstOrFail()->getAttributes();

        $this->artisan('carts:expire-stale')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Stale active carts', 1],
                    ['Already expired carts', 1],
                    ['Converted carts', 1],
                    ['Merged carts', 1],
                    ['Records that would change', 1],
                    ['Changed carts', 0],
                ],
            )
            ->expectsOutputToContain('Mode: preview (no writes)')
            ->assertSuccessful();

        $this->assertSame($cartSnapshot, Cart::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($itemSnapshot, $fixtures['stale']->items()->firstOrFail()->getAttributes());
        $this->assertSame($bundleSnapshot, $fixtures['stale']->bundleItems()->firstOrFail()->getAttributes());
    }

    public function test_apply_expires_only_stale_active_carts_without_deleting_content_and_is_idempotent(): void
    {
        $fixtures = $this->fixtures();
        $cartCount = Cart::query()->count();
        $itemCount = $fixtures['stale']->items()->count();
        $bundleCount = $fixtures['stale']->bundleItems()->count();

        $this->artisan('carts:expire-stale', ['--apply' => true])
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Stale active carts', 1],
                    ['Already expired carts', 1],
                    ['Converted carts', 1],
                    ['Merged carts', 1],
                    ['Records that would change', 1],
                    ['Changed carts', 1],
                ],
            )
            ->expectsOutputToContain('Mode: apply')
            ->assertSuccessful();

        $this->assertSame(CartStatus::Expired->value, $fixtures['stale']->fresh()->status);
        $this->assertSame(CartStatus::Active->value, $fixtures['future']->fresh()->status);
        $this->assertSame(CartStatus::Active->value, $fixtures['nullExpiry']->fresh()->status);
        $this->assertSame(CartStatus::Expired->value, $fixtures['expired']->fresh()->status);
        $this->assertSame(CartStatus::Converted->value, $fixtures['converted']->fresh()->status);
        $this->assertSame(CartStatus::Merged->value, $fixtures['merged']->fresh()->status);
        $this->assertSame($cartCount, Cart::query()->count());
        $this->assertSame($itemCount, $fixtures['stale']->items()->count());
        $this->assertSame($bundleCount, $fixtures['stale']->bundleItems()->count());

        $this->artisan('carts:expire-stale', ['--apply' => true])
            ->expectsOutputToContain('Mode: apply')
            ->assertSuccessful();

        $this->assertSame($cartCount, Cart::query()->count());
        $this->assertSame($itemCount, $fixtures['stale']->items()->count());
        $this->assertSame($bundleCount, $fixtures['stale']->bundleItems()->count());
    }

    public function test_expiration_command_is_not_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) ($event->command ?? ''));

        $this->assertFalse(
            $commands->contains(fn (string $command): bool => str_contains($command, 'carts:expire-stale')),
        );
    }

    private function fixtures(): array
    {
        $stale = $this->cart('stale', CartStatus::Active, now()->subSecond());
        $future = $this->cart('future', CartStatus::Active, now()->addDay());
        $nullExpiry = $this->cart('null-expiry', CartStatus::Active, null);
        $expired = $this->cart('expired', CartStatus::Expired, now()->subDay());
        $converted = $this->cart('converted', CartStatus::Converted, now()->subDay());
        $merged = $this->cart('merged', CartStatus::Merged, now()->subDay());
        $product = Product::factory()->create();
        $bundle = ProductBundle::query()->create([
            'name' => 'Expiry test bundle',
            'slug' => 'expiry-test-bundle',
            'status' => 'active',
            'type' => 'fixed_bundle',
            'pricing_type' => 'fixed_price',
            'fixed_price' => 50,
        ]);

        $stale->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 25,
            'total_price' => 25,
        ]);
        $stale->bundleItems()->create([
            'product_bundle_id' => $bundle->id,
            'selected_items' => [],
            'quantity' => 1,
            'unit_price' => 50,
            'total_price' => 50,
        ]);

        return compact('stale', 'future', 'nullExpiry', 'expired', 'converted', 'merged');
    }

    private function cart(string $seed, CartStatus $status, $expiresAt): Cart
    {
        return Cart::query()->create([
            'session_id' => $this->cartSession('expire-command-'.$seed),
            'status' => $status->value,
            'expires_at' => $expiresAt,
        ]);
    }
}
