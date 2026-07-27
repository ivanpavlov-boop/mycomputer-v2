<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Jobs\ConversionTrackingJob;
use App\Jobs\SendEmailJob;
use App\Models\CheckoutConfirmationCapability;
use App\Models\CheckoutIdempotencyRecord;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\PaymentTransaction;
use App\Services\Orders\CheckoutConfirmationService;
use App\Services\Orders\CheckoutIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_checkout_requires_one_exact_256_bit_base64url_key(): void
    {
        $cart = $this->prepareCheckoutCart('key-validation');
        $invalid = [
            null,
            '',
            ' ',
            'A',
            str_repeat('A', 42),
            str_repeat('A', 44),
            str_repeat('A', 42).'=',
            str_repeat('A', 42).'+',
            ' '.str_repeat('A', 43),
            str_repeat('A', 43).' ',
        ];

        foreach ($invalid as $index => $key) {
            $request = $this->withHeader('X-Cart-Session', $cart->session_id);

            if ($key !== null) {
                $request->withHeader('Idempotency-Key', $key);
            }

            $request
                ->postJson('/api/v1/checkout', $this->checkoutPayload())
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'checkout_idempotency_key_invalid');

            $this->assertDatabaseCount('checkout_idempotency_records', 0);
        }

        $this->submitCheckout($cart, 'key-validation')->assertCreated();
    }

    public function test_first_guest_checkout_persists_hash_only_identity_and_all_checkout_effects_once(): void
    {
        Queue::fake([ConversionTrackingJob::class, SendEmailJob::class]);
        Event::fake([OrderCreated::class]);
        $cart = $this->prepareCheckoutCart('first-guest');
        $product = $cart->items()->firstOrFail()->product;
        $stockBefore = $product->quantity;
        $rawKey = $this->checkoutIdempotencyKey('first-guest');

        $response = $this->submitCheckout($cart, 'first-guest')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false)
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.key_hash')
            ->assertJsonMissingPath('data.request_hash');

        $order = Order::query()->sole();
        $record = CheckoutIdempotencyRecord::query()->sole();

        $this->assertSame($cart->id, $record->cart_id);
        $this->assertSame($order->id, $record->order_id);
        $this->assertSame(hash('sha256', $rawKey), $record->key_hash);
        $this->assertNotSame(hash('sha256', json_encode($this->checkoutPayload())), $record->request_hash);
        $this->assertSame(64, strlen($record->request_hash));
        $this->assertSame(CheckoutIdempotencyRecord::STATUS_COMPLETED, $record->status);
        $this->assertNotNull($record->completed_at);
        $this->assertSame('converted', $cart->fresh()->status);
        $this->assertSame($stockBefore - 1, $product->fresh()->quantity);
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(1, OrderShipment::query()->count());
        $this->assertSame(1, CheckoutConfirmationCapability::query()->count());
        $this->assertArrayNotHasKey('key_hash', $record->toArray());
        $this->assertArrayNotHasKey('request_hash', $record->toArray());
        $this->assertNull($record->resolveRouteBinding($record->id));
        $this->assertStringNotContainsString($rawKey, $response->getContent());

        $columns = Schema::getColumnListing('checkout_idempotency_records');
        foreach (['email', 'phone', 'name', 'address', 'vat_number', 'notes', 'payload', 'response'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        Queue::assertPushed(ConversionTrackingJob::class, 1);
        Queue::assertPushed(SendEmailJob::class, 1);
        Event::assertDispatched(OrderCreated::class, 1);
    }

    public function test_same_key_replay_returns_the_original_result_without_repeating_side_effects(): void
    {
        Queue::fake([ConversionTrackingJob::class, SendEmailJob::class]);
        Event::fake([OrderCreated::class]);
        $cart = $this->prepareCheckoutCart('same-key-replay');
        $product = $cart->items()->firstOrFail()->product;

        $first = $this->submitCheckout($cart, 'same-key-replay')->assertCreated();
        $stockAfterFirst = $product->fresh()->quantity;
        $firstToken = $first->getCookie(CheckoutConfirmationService::COOKIE_NAME, false)?->getValue();
        $replay = $this->submitCheckout($cart, 'same-key-replay')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'))
            ->assertJsonPath('data.grand_total', $first->json('data.grand_total'));
        $replayToken = $replay->getCookie(CheckoutConfirmationService::COOKIE_NAME, false)?->getValue();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, CheckoutIdempotencyRecord::query()->count());
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(1, OrderShipment::query()->count());
        $this->assertSame($stockAfterFirst, $product->fresh()->quantity);
        $this->assertSame(2, CheckoutConfirmationCapability::query()->count());
        $this->assertIsString($firstToken);
        $this->assertIsString($replayToken);
        $this->assertNotSame($firstToken, $replayToken);
        $this->assertSame(
            Order::query()->sole()->id,
            app(CheckoutConfirmationService::class)->resolve($firstToken)->order_id,
        );
        $this->assertSame(
            Order::query()->sole()->id,
            app(CheckoutConfirmationService::class)->resolve($replayToken)->order_id,
        );
        Queue::assertPushed(ConversionTrackingJob::class, 1);
        Queue::assertPushed(SendEmailJob::class, 1);
        Event::assertDispatched(OrderCreated::class, 1);
    }

    public function test_different_key_with_equivalent_original_cart_returns_the_same_order(): void
    {
        $cart = $this->prepareCheckoutCart('different-key-replay');
        $first = $this->submitCheckout($cart, 'different-key-a')->assertCreated();

        $this->submitCheckout($cart, 'different-key-b')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));

        $this->assertDatabaseCount('checkout_idempotency_records', 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_same_key_or_converted_cart_with_changed_payload_returns_safe_conflict(): void
    {
        $cart = $this->prepareCheckoutCart('payload-conflict');
        $this->submitCheckout($cart, 'payload-conflict')->assertCreated();

        $sameKey = $this->submitCheckout(
            $cart,
            'payload-conflict',
            ['notes' => 'Different notes'],
        );
        $sameKey
            ->assertConflict()
            ->assertJsonPath('error.code', 'checkout_idempotency_conflict');

        $differentKey = $this->submitCheckout(
            $cart,
            'payload-conflict-other-key',
            ['payment_method' => 'bank_transfer'],
        );
        $differentKey
            ->assertConflict()
            ->assertJsonPath('error.code', 'checkout_already_completed');

        foreach ([$sameKey, $differentKey] as $response) {
            $response
                ->assertJsonMissingPath('error.order_number')
                ->assertJsonMissingPath('error.order_id')
                ->assertJsonMissingPath('error.key_hash')
                ->assertJsonMissingPath('error.request_hash');
            $this->assertNull($response->getCookie(CheckoutConfirmationService::COOKIE_NAME, false));
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('checkout_idempotency_records', 1);
    }

    public function test_checkout_route_uses_the_narrow_limiter_and_fingerprint_is_order_stable(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/checkout'
                && in_array('POST', $route->methods(), true),
        );
        $this->assertNotNull($route);
        $this->assertContains('throttle:checkout', $route->gatherMiddleware());

        $service = app(CheckoutIdempotencyService::class);
        $left = $service->fingerprintPayload([
            'email' => 'customer@example.test',
            'shipping' => ['city' => 'Sofia', 'lines' => [2, 1]],
        ]);
        $right = $service->fingerprintPayload([
            'shipping' => ['lines' => [2, 1], 'city' => 'Sofia'],
            'email' => 'customer@example.test',
        ]);
        $differentListOrder = $service->fingerprintPayload([
            'email' => 'customer@example.test',
            'shipping' => ['city' => 'Sofia', 'lines' => [1, 2]],
        ]);

        $this->assertSame($left, $right);
        $this->assertNotSame($left, $differentListOrder);
    }

    public function test_idempotency_migration_has_the_required_persistent_constraints(): void
    {
        $this->assertSame([
            'id',
            'cart_id',
            'order_id',
            'key_hash',
            'request_hash',
            'status',
            'completed_at',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('checkout_idempotency_records'));

        $indexes = collect(Schema::getIndexes('checkout_idempotency_records'));
        foreach (['cart_id', 'order_id', 'key_hash'] as $column) {
            $this->assertTrue($indexes->contains(
                fn (array $index): bool => $index['unique'] && $index['columns'] === [$column],
            ));
        }
        foreach (['status', 'completed_at'] as $column) {
            $this->assertTrue($indexes->contains(
                fn (array $index): bool => $index['columns'] === [$column],
            ));
        }

        $foreignKeys = collect(Schema::getForeignKeys('checkout_idempotency_records'));
        $this->assertTrue($foreignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['cart_id']
                && $foreignKey['foreign_table'] === 'carts'
                && strtolower((string) $foreignKey['on_delete']) === 'restrict',
        ));
        $this->assertTrue($foreignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['order_id']
                && $foreignKey['foreign_table'] === 'orders'
                && strtolower((string) $foreignKey['on_delete']) === 'restrict',
        ));

        $cart = $this->prepareCheckoutCart('persistent-rollback');
        $this->submitCheckout($cart, 'persistent-rollback')->assertCreated();
        $migration = require database_path(
            'migrations/2026_07_27_090000_create_checkout_idempotency_records_table.php',
        );

        try {
            $migration->down();
            $this->fail('Persistent checkout identities must not be dropped while records exist.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Refusing to drop persistent checkout idempotency records while records exist.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('checkout_idempotency_records'));
    }
}
