<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Services\Commerce\PublicCommerceReleaseGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicCommerceReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('releaseStates')]
    public function test_release_state_matrix(
        mixed $enabled,
        mixed $confirmationEnabled,
        string $expectedState,
        bool $canStartCheckout,
        bool $canShowConfirmation,
        bool $valid,
    ): void {
        config()->set('commerce.public.enabled', $enabled);
        config()->set('commerce.public.confirmation_enabled', $confirmationEnabled);

        $gate = app(PublicCommerceReleaseGate::class);

        $this->assertSame($expectedState, $gate->state());
        $this->assertSame($canStartCheckout, $gate->canStartCheckout());
        $this->assertSame($canShowConfirmation, $gate->canShowConfirmation());
        $this->assertSame($valid, $gate->isValidConfiguration());
    }

    public static function releaseStates(): array
    {
        return [
            'closed' => [false, false, 'closed', false, false, true],
            'confirmation only' => [false, true, 'confirmation_only', false, true, true],
            'open' => [true, true, 'open', true, true, true],
            'invalid dependency' => [true, false, 'invalid', false, false, false],
            'invalid public value' => ['yes', true, 'invalid', false, false, false],
            'invalid confirmation value' => [false, '1', 'invalid', false, false, false],
        ];
    }

    public function test_closed_checkout_is_a_generic_no_store_404_with_zero_side_effects(): void
    {
        config()->set('commerce.public.enabled', false);
        config()->set('commerce.public.confirmation_enabled', false);
        Mail::fake();
        Notification::fake();

        $product = Product::factory()->supplierPublished()->create(['quantity' => 7]);
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession('release-gate-closed'),
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->effectivePrice(),
            'total_price' => $product->effectivePrice(),
        ]);
        $before = $this->checkoutSideEffectCounts();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey('release-gate-closed'))
            ->postJson('/api/v1/checkout', [
                'payment_method' => 'not-a-real-method',
            ])
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'success' => false,
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Not Found.',
                    'details' => null,
                ],
            ]);

        $this->assertSame($before, $this->checkoutSideEffectCounts());
        $this->assertSame(7, $product->fresh()->quantity);
        $this->assertSame('active', $cart->fresh()->status);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_confirmation_only_blocks_new_checkout_but_preserves_confirmation_route(): void
    {
        config()->set('commerce.public.enabled', false);
        config()->set('commerce.public.confirmation_enabled', true);

        $this->postJson('/api/v1/checkout')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->getJson('/api/v1/checkout/confirmation')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'checkout_confirmation_unavailable');

        $this->assertTrue($this->routeHasMiddleware(
            'api/v1/checkout/payment-attempts',
            'App\Http\Middleware\PrivatePaymentAttemptResponse',
        ));
        $this->assertFalse($this->routeHasMiddleware(
            'api/v1/checkout/payment-attempts',
            'App\Http\Middleware\EnsurePublicCommerceEnabled',
        ));
    }

    public function test_open_checkout_reaches_existing_validation_contract(): void
    {
        config()->set('commerce.public.enabled', true);
        config()->set('commerce.public.confirmation_enabled', true);

        $this->postJson('/api/v1/checkout')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_release_middleware_is_scoped_only_to_new_checkout_creation(): void
    {
        $guardedRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array(
                'App\Http\Middleware\EnsurePublicCommerceEnabled',
                $route->gatherMiddleware(),
                true,
            ))
            ->map(fn ($route): array => [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'uri' => 'api/v1/checkout',
                'methods' => ['POST'],
            ],
        ], $guardedRoutes);
    }

    public function test_committed_release_defaults_are_fail_closed(): void
    {
        $environmentExample = (string) file_get_contents(base_path('.env.example'));
        $commerceConfig = (string) file_get_contents(base_path('config/commerce.php'));

        $this->assertStringContainsString('PUBLIC_COMMERCE_ENABLED=false', $environmentExample);
        $this->assertStringContainsString(
            'PUBLIC_COMMERCE_CONFIRMATION_ENABLED=false',
            $environmentExample,
        );
        $this->assertStringContainsString(
            'ABANDONED_CART_RECOVERY_ENABLED=false',
            $environmentExample,
        );
        $this->assertStringContainsString(
            "env('PUBLIC_COMMERCE_ENABLED', false)",
            $commerceConfig,
        );
        $this->assertStringContainsString(
            "env('PUBLIC_COMMERCE_CONFIRMATION_ENABLED', false)",
            $commerceConfig,
        );
        $this->assertStringContainsString(
            "env('ABANDONED_CART_RECOVERY_ENABLED', false)",
            $commerceConfig,
        );
    }

    /**
     * @return array<string, int>
     */
    private function checkoutSideEffectCounts(): array
    {
        return collect([
            'checkout_idempotency_records',
            'customers',
            'orders',
            'order_items',
            'payment_transactions',
            'payment_attempts',
            'order_shipments',
            'leasing_applications',
            'promotion_redemptions',
            'loyalty_transactions',
            'checkout_confirmation_capabilities',
            'payment_retry_capabilities',
            'email_logs',
            'marketing_events',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count(),
        ])->all();
    }

    private function routeHasMiddleware(string $uri, string $middleware): bool
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === $uri)
            ->contains(fn ($route): bool => in_array($middleware, $route->gatherMiddleware(), true));
    }
}
