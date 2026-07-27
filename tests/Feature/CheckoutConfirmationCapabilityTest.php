<?php

namespace Tests\Feature;

use App\Models\CheckoutConfirmationCapability;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CheckoutConfirmationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_checkout_issues_one_hash_only_capability_and_secure_cookie_contract(): void
    {
        $response = $this->checkout('guest-capability');

        $response
            ->assertCreated()
            ->assertCookie(CheckoutConfirmationService::COOKIE_NAME, null, false)
            ->assertCookieNotExpired(CheckoutConfirmationService::COOKIE_NAME)
            ->assertJsonMissingPath('data.confirmation_token')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');

        $cookie = $response->getCookie(CheckoutConfirmationService::COOKIE_NAME, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertFalse($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame(CheckoutConfirmationService::LIFETIME_SECONDS, $cookie->getMaxAge());
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $cookie->getValue());

        $capability = CheckoutConfirmationCapability::query()->sole();
        $this->assertSame(Order::query()->sole()->id, $capability->order_id);
        $this->assertSame(hash('sha256', $cookie->getValue()), $capability->token_hash);
        $this->assertNotSame($cookie->getValue(), $capability->token_hash);
        $this->assertSame(64, strlen($capability->token_hash));
        $this->assertArrayNotHasKey('token_hash', $capability->toArray());
        $this->assertNull($capability->resolveRouteBinding($capability->id));
        $this->assertEqualsWithDelta(
            now()->addMinutes(CheckoutConfirmationService::LIFETIME_MINUTES)->timestamp,
            $capability->expires_at->timestamp,
            5,
        );
    }

    public function test_authenticated_checkout_issues_one_capability_for_its_order(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $response = $this->checkout(
            'authenticated-capability',
            $user,
            ['email' => $user->email],
        );

        $response->assertCreated();
        $order = Order::query()->sole();

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame($order->id, CheckoutConfirmationCapability::query()->sole()->order_id);
    }

    public function test_one_order_can_receive_multiple_hash_only_capabilities(): void
    {
        $this->checkout('duplicate-capability')->assertCreated();
        $order = Order::query()->sole();
        $first = CheckoutConfirmationCapability::query()->sole();
        $secondToken = app(CheckoutConfirmationService::class)->issue($order);

        $this->assertSame(2, $order->checkoutConfirmationCapabilities()->count());
        $this->assertNotSame($first->token_hash, hash('sha256', $secondToken));
        $this->assertSame($order->id, app(CheckoutConfirmationService::class)->resolve($secondToken)->order_id);
    }

    public function test_https_and_production_cookie_security_are_fail_closed(): void
    {
        $service = app(CheckoutConfirmationService::class);
        $httpsCookie = $service->cookie('fixture-token', Request::create('https://example.test'));
        $this->assertTrue($httpsCookie->isSecure());

        $originalEnvironment = $this->app->environment();
        $this->app['env'] = 'production';

        try {
            $productionCookie = $service->cookie('fixture-token', Request::create('http://example.test'));
            $this->assertTrue($productionCookie->isSecure());
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_migration_has_narrow_constraints_and_fails_closed_on_non_empty_rollback(): void
    {
        $this->assertTrue(Schema::hasTable('checkout_confirmation_capabilities'));
        $this->assertSame([
            'id',
            'order_id',
            'token_hash',
            'expires_at',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('checkout_confirmation_capabilities'));

        $indexes = collect(Schema::getIndexes('checkout_confirmation_capabilities'));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => ! $index['unique'] && $index['columns'] === ['order_id'],
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['unique'] && $index['columns'] === ['token_hash'],
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['expires_at'],
        ));

        $foreignKeys = collect(Schema::getForeignKeys('checkout_confirmation_capabilities'));
        $this->assertTrue($foreignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['order_id']
                && $foreignKey['foreign_table'] === 'orders'
                && strtolower((string) $foreignKey['on_delete']) === 'cascade',
        ));

        $this->checkout('rollback-capability')->assertCreated();
        $migration = require database_path(
            'migrations/2026_07_26_090000_create_checkout_confirmation_capabilities_table.php',
        );

        try {
            $migration->down();
            $this->fail('A non-empty confirmation table must not be dropped.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Refusing to drop checkout confirmation capabilities while records exist.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('checkout_confirmation_capabilities'));
    }

    public function test_empty_capability_table_can_be_rolled_back_and_restored(): void
    {
        $migration = require database_path(
            'migrations/2026_07_26_090000_create_checkout_confirmation_capabilities_table.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('checkout_confirmation_capabilities'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('checkout_confirmation_capabilities'));
    }

    private function checkout(
        string $sessionName,
        ?User $user = null,
        array $overrides = [],
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
            ->postJson('/api/v1/checkout', $this->checkoutPayload($overrides));
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_replace([
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
        ], $overrides);
    }
}
