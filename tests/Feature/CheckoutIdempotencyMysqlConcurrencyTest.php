<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderPaymentStatusChanged;
use App\Exceptions\CheckoutAlreadyCompletedException;
use App\Exceptions\CheckoutIdempotencyConflictException;
use App\Jobs\ConversionTrackingJob;
use App\Jobs\SendEmailJob;
use App\Models\Cart;
use App\Models\CheckoutIdempotencyRecord;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\IdempotentCheckoutService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CheckoutIdempotencyMysqlConcurrencyTest extends TestCase
{
    public function test_mysql_checkout_serializes_same_and_different_keys_and_payloads(): void
    {
        $this->requireMysqlConcurrency();
        $this->seed();

        $sameKey = $this->runScenario('same-key', [
            ['key' => 'same-key', 'overrides' => []],
            ['key' => 'same-key', 'overrides' => []],
        ]);
        $this->assertSame([201, 201], $sameKey->pluck('status')->sort()->values()->all());
        $this->assertSame(1, $sameKey->pluck('order_number')->unique()->count());

        $differentKeys = $this->runScenario('different-keys', [
            ['key' => 'different-key-a', 'overrides' => []],
            ['key' => 'different-key-b', 'overrides' => []],
        ]);
        $this->assertSame([201, 201], $differentKeys->pluck('status')->sort()->values()->all());
        $this->assertSame(1, $differentKeys->pluck('order_number')->unique()->count());

        $differentPayloads = $this->runScenario('different-payloads', [
            ['key' => 'different-payload-a', 'overrides' => []],
            ['key' => 'different-payload-b', 'overrides' => ['notes' => 'Changed payload']],
        ]);
        $this->assertSame([201, 409], $differentPayloads->pluck('status')->sort()->values()->all());
        $this->assertSame(
            ['checkout_already_completed'],
            $differentPayloads->pluck('code')->filter()->values()->all(),
        );

        $rollbackRace = $this->runScenario('rollback-race', [
            ['key' => 'rollback-race-a', 'overrides' => [], 'fail' => true],
            ['key' => 'rollback-race-b', 'overrides' => [], 'wait_for_failure' => true],
        ]);
        $this->assertSame([201, 500], $rollbackRace->pluck('status')->sort()->values()->all());
        $this->assertSame(1, $rollbackRace->pluck('order_number')->filter()->unique()->count());
    }

    public function test_mysql_transaction_retry_commits_once_and_dispatches_once(): void
    {
        $this->requireMysqlConcurrency();
        $this->seed();
        Queue::fake([ConversionTrackingJob::class, SendEmailJob::class]);
        Event::fake([OrderCreated::class, OrderPaymentStatusChanged::class]);
        [$cart, $product, $stockBefore] = $this->cartFixture('transaction-retry');
        $shipmentService = app(ShipmentService::class);
        $attempts = 0;
        $mock = Mockery::mock(ShipmentService::class);
        $mock->shouldReceive('create')
            ->twice()
            ->andReturnUsing(function ($order, array $data) use (&$attempts, $shipmentService) {
                $attempts++;

                if ($attempts === 1) {
                    throw new PDOException(
                        'Deadlock found when trying to get lock; try restarting transaction',
                        40001,
                    );
                }

                return $shipmentService->create($order, $data);
            });
        $this->app->instance(ShipmentService::class, $mock);

        try {
            $result = app(IdempotentCheckoutService::class)->checkout(
                $this->checkoutRequest($cart, 'transaction-retry', []),
                $this->checkoutPayload(),
            );

            $this->assertSame(2, $attempts);
            $this->assertFalse($result->replayed());
            $this->assertScenarioEffects($cart, $product, $stockBefore);
            Queue::assertPushed(ConversionTrackingJob::class, 1);
            Queue::assertPushed(SendEmailJob::class, 1);
            Event::assertDispatched(OrderCreated::class, 1);
            Event::assertDispatched(OrderPaymentStatusChanged::class, 1);
        } finally {
            $this->cleanupScenario($cart, $product, $stockBefore);
        }
    }

    private function runScenario(string $name, array $attempts)
    {
        [$cart, $product, $stockBefore] = $this->cartFixture($name);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'checkout-idempotency-'.Str::uuid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $failureBarrier = $directory.DIRECTORY_SEPARATOR.'failure-barrier';
        $children = [];
        $waited = [];

        $this->purgeDatabaseConnections();

        if (! mkdir($directory)) {
            throw new RuntimeException('Unable to create checkout synchronization directory.');
        }

        try {
            foreach ($attempts as $index => $attempt) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork checkout test process.');
                }

                if ($pid === 0) {
                    $this->runChild(
                        $index,
                        $attempt,
                        $cart->id,
                        $cart->session_id,
                        $startFile,
                        $failureBarrier,
                        $directory,
                    );
                }

                $children[] = $pid;
            }

            touch($startFile);

            foreach ($children as $pid) {
                $waitedPid = pcntl_waitpid($pid, $status);
                $waited[] = $pid;
                $this->assertSame($pid, $waitedPid);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $results = collect(array_keys($attempts))->map(function (int $index) use ($directory): array {
                $path = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                $this->assertFileExists($path);

                return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            });
            $this->purgeDatabaseConnections();
            $this->assertScenarioEffects($cart, $product, $stockBefore);

            return $results;
        } finally {
            if (! file_exists($startFile)) {
                touch($startFile);
            }

            foreach (array_diff($children, $waited) as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $this->purgeDatabaseConnections();
            $this->cleanupScenario($cart, $product, $stockBefore);

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function runChild(
        int $index,
        array $attempt,
        int $cartId,
        string $cartSession,
        string $startFile,
        string $failureBarrier,
        string $directory,
    ): never {
        while (! file_exists($startFile)) {
            usleep(1_000);
        }

        $this->purgeDatabaseConnections();

        if (($attempt['fail'] ?? false) === true) {
            $mock = Mockery::mock(ShipmentService::class);
            $mock->shouldReceive('create')->once()->andReturnUsing(
                function () use ($failureBarrier): never {
                    touch($failureBarrier);
                    $deadline = microtime(true) + 5;

                    while (! file_exists($failureBarrier.'.waiter') && microtime(true) < $deadline) {
                        usleep(1_000);
                    }

                    throw new RuntimeException('Synthetic rollback-race failure.');
                },
            );
            $this->app->instance(ShipmentService::class, $mock);
        } elseif (($attempt['wait_for_failure'] ?? false) === true) {
            $deadline = microtime(true) + 5;

            while (! file_exists($failureBarrier) && microtime(true) < $deadline) {
                usleep(1_000);
            }

            touch($failureBarrier.'.waiter');
        }

        $overrides = $attempt['overrides'] ?? [];
        $payload = $this->checkoutPayload($overrides);

        try {
            $cart = Cart::query()->findOrFail($cartId);
            $result = app(IdempotentCheckoutService::class)->checkout(
                $this->checkoutRequest($cart, $attempt['key'], $overrides, $cartSession),
                $payload,
            );
            $response = [
                'status' => 201,
                'code' => null,
                'order_number' => $result->order()->order_number,
                'replayed' => $result->replayed(),
            ];
        } catch (CheckoutAlreadyCompletedException) {
            $response = ['status' => 409, 'code' => 'checkout_already_completed'];
        } catch (CheckoutIdempotencyConflictException) {
            $response = ['status' => 409, 'code' => 'checkout_idempotency_conflict'];
        } catch (Throwable $exception) {
            $response = ['status' => 500, 'code' => get_class($exception)];
        }

        $written = file_put_contents(
            $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
            json_encode($response, JSON_THROW_ON_ERROR),
        );
        $this->purgeDatabaseConnections();

        exit($written === false ? 1 : 0);
    }

    private function assertScenarioEffects(Cart $cart, Product $product, int $stockBefore): void
    {
        $record = CheckoutIdempotencyRecord::query()->where('cart_id', $cart->id)->sole();
        $this->assertSame('completed', $record->status);
        $this->assertNotNull($record->order_id);
        $this->assertSame(1, Order::query()->whereKey($record->order_id)->count());
        $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $record->order_id)->count());
        $this->assertSame(1, DB::table('order_shipments')->where('order_id', $record->order_id)->count());
        $this->assertSame(1, DB::table('checkout_confirmation_capabilities')->where('order_id', $record->order_id)->count());
        $this->assertSame($stockBefore - 1, (int) $product->fresh()->quantity);
        $this->assertSame('converted', $cart->fresh()->status);
    }

    private function cartFixture(string $name): array
    {
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update([
            'active' => true,
            'workflow_status' => 'published',
            'product_status' => 'active',
            'published_at' => now(),
            'price' => 100,
            'regular_price' => 100,
            'promo_price' => null,
            'quantity' => 100,
        ]);
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession('mysql-'.$name),
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'is_gift' => false,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        return [$cart, $product, 100];
    }

    private function checkoutRequest(
        Cart $cart,
        string $key,
        array $overrides = [],
        ?string $cartSession = null,
    ): Request {
        $request = Request::create('/api/v1/checkout', 'POST', $this->checkoutPayload($overrides));
        $request->headers->set('X-Cart-Session', $cartSession ?? $cart->session_id);
        $request->headers->set('Idempotency-Key', $this->checkoutIdempotencyKey($key));

        return $request;
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'checkout-concurrency@example.test',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'notes' => 'Concurrent checkout',
            'terms' => true,
        ], $overrides);
    }

    private function cleanupScenario(Cart $cart, Product $product, int $stockBefore): void
    {
        $record = CheckoutIdempotencyRecord::query()->where('cart_id', $cart->id)->first();
        $orderId = $record?->order_id;
        $record?->delete();

        DB::table('promotion_redemptions')->where('session_id', $cart->session_id)->delete();
        DB::table('abandoned_cart_records')->where('session_id', $cart->session_id)->delete();
        DB::table('marketing_events')->where('session_id', $cart->session_id)->delete();

        if ($orderId !== null) {
            Order::query()->whereKey($orderId)->delete();
        }

        Cart::query()->whereKey($cart->id)->delete();
        Product::query()->whereKey($product->id)->update(['quantity' => $stockBefore]);
    }

    private function purgeDatabaseConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $connectionName) {
            DB::purge($connectionName);
        }
    }

    private function requireMysqlConcurrency(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for MySQL concurrency validation.');
        }
    }
}
