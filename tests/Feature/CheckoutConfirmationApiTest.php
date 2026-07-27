<?php

namespace Tests\Feature;

use App\Models\CheckoutConfirmationCapability;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CheckoutConfirmationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_cookie_returns_only_trusted_minimal_confirmation_with_no_store_headers(): void
    {
        $checkout = $this->checkout('trusted-confirmation', [
            'email' => 'ivan.petrov@example.test',
            'payment_method' => 'bank_transfer',
        ]);
        $confirmation = $this->confirmation($checkout);

        $confirmation
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('data.order_number', Order::query()->sole()->order_number)
            ->assertJsonPath('data.grand_total', Order::query()->sole()->grand_total)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.order_status', 'pending')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment_method.code', 'bank_transfer')
            ->assertJsonPath('data.customer_email_masked', 'i***@example.test')
            ->assertJsonPath(
                'data.payment.instructions',
                'Очаквайте банкови данни и основание за плащане в потвърждението.',
            );
        $this->assertEqualsCanonicalizing(
            ['private', 'no-store', 'max-age=0'],
            array_map('trim', explode(',', (string) $confirmation->headers->get('Cache-Control'))),
        );

        foreach ([
            'id',
            'customer_id',
            'user_id',
            'customer_email',
            'customer_phone',
            'customer_name',
            'billing_address',
            'shipping_address',
            'company_name',
            'vat_number',
            'cart_id',
            'cart_session_id',
            'token',
            'token_hash',
            'payment_transactions',
            'raw_response',
        ] as $forbidden) {
            $confirmation->assertJsonMissingPath("data.{$forbidden}");
        }
    }

    public function test_missing_malformed_unknown_expired_and_deleted_order_use_same_generic_response(): void
    {
        $expected = [
            'success' => false,
            'error' => [
                'code' => 'checkout_confirmation_unavailable',
                'message' => 'Checkout confirmation is unavailable.',
                'details' => null,
            ],
        ];

        $this->getJson('/api/v1/checkout/confirmation')
            ->assertNotFound()
            ->assertExactJson($expected);

        $this->withCredentials()
            ->withUnencryptedCookie(CheckoutConfirmationService::COOKIE_NAME, 'malformed')
            ->getJson('/api/v1/checkout/confirmation')
            ->assertNotFound()
            ->assertExactJson($expected);

        $this->withCredentials()->withUnencryptedCookie(
            CheckoutConfirmationService::COOKIE_NAME,
            str_repeat('A', 43),
        )->getJson('/api/v1/checkout/confirmation')
            ->assertNotFound()
            ->assertExactJson($expected);

        $checkout = $this->checkout('expired-confirmation');
        $token = $this->token($checkout);
        CheckoutConfirmationCapability::query()->update(['expires_at' => now()->subSecond()]);

        $this->withCredentials()
            ->withUnencryptedCookie(CheckoutConfirmationService::COOKIE_NAME, $token)
            ->getJson('/api/v1/checkout/confirmation')
            ->assertNotFound()
            ->assertExactJson($expected);

        $deletedOrder = Order::query()->sole()->replicate();
        $deletedOrder->order_number = 'MC-DELETED-CONFIRMATION';
        $deletedOrder->save();
        $deletedToken = app(CheckoutConfirmationService::class)->issue($deletedOrder);
        $deletedOrder->delete();

        $this->withCredentials()
            ->withUnencryptedCookie(CheckoutConfirmationService::COOKIE_NAME, $deletedToken)
            ->getJson('/api/v1/checkout/confirmation')
            ->assertNotFound()
            ->assertExactJson($expected);
    }

    public function test_query_body_and_public_header_values_never_authorize_confirmation(): void
    {
        $checkout = $this->checkout('authority-confirmation');
        $token = $this->token($checkout);
        $order = Order::query()->sole();

        $this->getJson('/api/v1/checkout/confirmation?token='.$token
            .'&order_id='.$order->id
            .'&order_number='.$order->order_number
            .'&email=attacker@example.test')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'checkout_confirmation_unavailable');

        $this->withHeader('X-Checkout-Confirmation', $token)
            ->json('GET', '/api/v1/checkout/confirmation', ['token' => $token])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'checkout_confirmation_unavailable');
    }

    public function test_valid_cookie_ignores_tampered_query_and_can_be_reloaded_during_ttl(): void
    {
        $checkout = $this->checkout('reload-confirmation');
        $token = $this->token($checkout);
        $order = Order::query()->sole();
        $path = '/api/v1/checkout/confirmation?order=FAKE&total=0&email=attacker@example.test';

        foreach (range(1, 2) as $reload) {
            $this->withCredentials()
                ->withUnencryptedCookie(CheckoutConfirmationService::COOKIE_NAME, $token)
                ->getJson($path)
                ->assertOk()
                ->assertJsonPath('data.order_number', $order->order_number)
                ->assertJsonPath('data.grand_total', $order->grand_total)
                ->assertJsonMissing(['FAKE', 'attacker@example.test']);
        }
    }

    public function test_authentication_without_cookie_does_not_authorize_another_users_order(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->checkout('owner-confirmation', ['email' => $owner->email], $owner);
        $unrelated = User::factory()->create(['email' => 'unrelated@example.test']);

        $this->actingAs($unrelated, 'sanctum')
            ->getJson('/api/v1/checkout/confirmation?order_number='.Order::query()->sole()->order_number)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'checkout_confirmation_unavailable');
    }

    public function test_email_masking_handles_short_and_invalid_values_without_exposing_contact_data(): void
    {
        $service = app(CheckoutConfirmationService::class);

        $this->assertSame('a***@example.test', $service->maskEmail('a@example.test'));
        $this->assertSame('a***@example.test', $service->maskEmail('ab@example.test'));
        $this->assertSame('***', $service->maskEmail('invalid'));
        $this->assertSame('***', $service->maskEmail(null));
    }

    public function test_confirmation_route_uses_the_dedicated_rate_limiter(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/checkout/confirmation');

        $this->assertNotNull($route);
        $this->assertContains('throttle:checkout-confirmation', $route->gatherMiddleware());
    }

    private function checkout(
        string $sessionName,
        array $overrides = [],
        ?User $user = null,
    ): TestResponse {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $request = $user ? $this->actingAs($user, 'sanctum') : $this;

        $request->withHeader('X-Cart-Session', $this->cartSession($sessionName))
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk();

        return $request
            ->withHeader('X-Cart-Session', $this->cartSession($sessionName))
            ->withHeader('Idempotency-Key', $this->checkoutIdempotencyKey($sessionName))
            ->postJson('/api/v1/checkout', array_replace([
                'first_name' => 'Ivan',
                'last_name' => 'Petrov',
                'email' => 'confirmation@example.test',
                'phone' => '0888123456',
                'billing_address' => 'Sofia, Bulgaria',
                'shipping_address' => 'Sofia, Bulgaria',
                'payment_method' => 'cash_on_delivery',
                'shipping_method' => 'address_delivery',
                'shipping_provider' => 'manual',
                'city' => 'Sofia',
                'terms' => true,
            ], $overrides))
            ->assertCreated();
    }

    private function confirmation(TestResponse $checkout): TestResponse
    {
        return $this->withCredentials()->withUnencryptedCookie(
            CheckoutConfirmationService::COOKIE_NAME,
            $this->token($checkout),
        )->getJson('/api/v1/checkout/confirmation');
    }

    private function token(TestResponse $checkout): string
    {
        $cookie = $checkout->getCookie(CheckoutConfirmationService::COOKIE_NAME, false);
        $this->assertNotNull($cookie);

        return $cookie->getValue();
    }
}
