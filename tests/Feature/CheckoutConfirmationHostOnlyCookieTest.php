<?php

namespace Tests\Feature;

use App\Models\CheckoutConfirmationCapability;
use App\Models\Product;
use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class CheckoutConfirmationHostOnlyCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_https_checkout_issues_host_only_cookie_with_shared_session_domain(): void
    {
        $this->configureSharedSessionDomain();

        $response = $this->checkout('host-only-issue');

        $response
            ->assertCreated()
            ->assertCookie(CheckoutConfirmationService::COOKIE_NAME, null, false)
            ->assertCookieNotExpired(CheckoutConfirmationService::COOKIE_NAME);

        $cookie = $this->confirmationCookie($response);
        $header = $this->confirmationSetCookieHeader($response);

        $this->assertSame('.computer2u.eu', config('session.domain'));
        $this->assertNotSame('', $cookie->getValue());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertGreaterThanOrEqual(CheckoutConfirmationService::LIFETIME_SECONDS - 5, $cookie->getMaxAge());
        $this->assertLessThanOrEqual(CheckoutConfirmationService::LIFETIME_SECONDS, $cookie->getMaxAge());
        $this->assertCookieHeaderSecurity($header);
        $this->assertMatchesRegularExpression('/max-age=7(?:19[5-9]|200)/i', $header);
    }

    public function test_missing_https_capability_returns_host_only_deletion_cookie(): void
    {
        $this->configureSharedSessionDomain();

        $response = $this->getJson('https://example.test/api/v1/checkout/confirmation');

        $this->assertUnavailableDeletionResponse($response);
        $this->assertSame('.computer2u.eu', config('session.domain'));
    }

    public function test_malformed_and_expired_capabilities_return_host_only_deletion_cookies(): void
    {
        $this->configureSharedSessionDomain();

        $malformed = $this->withCredentials()
            ->withUnencryptedCookie(CheckoutConfirmationService::COOKIE_NAME, 'malformed')
            ->getJson('https://example.test/api/v1/checkout/confirmation');

        $this->assertUnavailableDeletionResponse($malformed);

        $checkout = $this->checkout('host-only-expired');
        $token = $this->confirmationCookie($checkout)->getValue();
        CheckoutConfirmationCapability::query()->update(['expires_at' => now()->subSecond()]);

        $expired = $this->withCredentials()
            ->withUnencryptedCookie(CheckoutConfirmationService::COOKIE_NAME, $token)
            ->getJson('https://example.test/api/v1/checkout/confirmation');

        $this->assertUnavailableDeletionResponse($expired);
    }

    public function test_service_cookies_are_host_only_without_changing_local_http_security(): void
    {
        $this->configureSharedSessionDomain();
        $service = app(CheckoutConfirmationService::class);
        $httpsRequest = Request::create('https://example.test');
        $httpRequest = Request::create('http://localhost');

        $issued = $service->cookie('fixture-token', $httpsRequest);
        $deleted = $service->forgetCookie($httpsRequest);
        $local = $service->cookie('fixture-token', $httpRequest);

        foreach ([$issued, $deleted, $local] as $cookie) {
            $this->assertNull($cookie->getDomain());
            $this->assertSame('/', $cookie->getPath());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            $this->assertStringNotContainsString('domain=', strtolower((string) $cookie));
        }

        $this->assertTrue($issued->isSecure());
        $this->assertTrue($deleted->isSecure());
        $this->assertFalse($local->isSecure());
        $this->assertLessThan(time(), $deleted->getExpiresTime());
        $this->assertSame(0, $deleted->getMaxAge());
        $this->assertSame('.computer2u.eu', config('session.domain'));
    }

    private function configureSharedSessionDomain(): void
    {
        config()->set('session.domain', '.computer2u.eu');
        config()->set('session.secure', true);
    }

    private function checkout(string $sessionName): TestResponse
    {
        $this->seed();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->withHeader('X-Cart-Session', $this->cartSession($sessionName))
            ->postJson('https://example.test/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk();

        return $this->withHeader('X-Cart-Session', $this->cartSession($sessionName))
            ->postJson('https://example.test/api/v1/checkout', [
                'first_name' => 'Ivan',
                'last_name' => 'Petrov',
                'email' => 'host-only@example.test',
                'phone' => '0888123456',
                'billing_address' => 'Sofia, Bulgaria',
                'shipping_address' => 'Sofia, Bulgaria',
                'payment_method' => 'cash_on_delivery',
                'shipping_method' => 'address_delivery',
                'shipping_provider' => 'manual',
                'city' => 'Sofia',
                'terms' => true,
            ]);
    }

    private function assertUnavailableDeletionResponse(TestResponse $response): void
    {
        $response
            ->assertNotFound()
            ->assertJsonPath('error.code', 'checkout_confirmation_unavailable')
            ->assertHeader('Pragma', 'no-cache')
            ->assertCookieExpired(CheckoutConfirmationService::COOKIE_NAME);

        $this->assertEqualsCanonicalizing(
            ['private', 'no-store', 'max-age=0'],
            array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))),
        );

        $cookie = $this->confirmationCookie($response);
        $header = $this->confirmationSetCookieHeader($response);

        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertLessThan(time(), $cookie->getExpiresTime());
        $this->assertSame(0, $cookie->getMaxAge());
        $this->assertCookieHeaderSecurity($header);
        $this->assertStringContainsString('max-age=0', strtolower($header));
    }

    private function confirmationCookie(TestResponse $response): Cookie
    {
        $cookie = $response->getCookie(CheckoutConfirmationService::COOKIE_NAME, false);

        $this->assertInstanceOf(Cookie::class, $cookie);

        return $cookie;
    }

    private function confirmationSetCookieHeader(TestResponse $response): string
    {
        $header = collect($response->headers->all('set-cookie'))
            ->first(fn (string $value): bool => str_starts_with(
                strtolower($value),
                CheckoutConfirmationService::COOKIE_NAME.'=',
            ));

        $this->assertIsString($header);

        return $header;
    }

    private function assertCookieHeaderSecurity(string $header): void
    {
        $normalized = strtolower($header);

        $this->assertStringNotContainsString('domain=', $normalized);
        $this->assertStringContainsString('path=/', $normalized);
        $this->assertStringContainsString('secure', $normalized);
        $this->assertStringContainsString('httponly', $normalized);
        $this->assertStringContainsString('samesite=lax', $normalized);
    }
}
