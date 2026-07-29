<?php

namespace Tests\Feature;

use App\Events\LeasingApplicationSubmitted;
use App\Events\OrderCreated;
use App\Events\OrderPaymentStatusChanged;
use App\Exceptions\CheckoutAlreadyCompletedException;
use App\Exceptions\CheckoutIdempotencyConflictException;
use App\Jobs\ConversionTrackingJob;
use App\Jobs\SendEmailJob;
use App\Models\Cart;
use App\Models\Category;
use App\Models\CheckoutIdempotencyRecord;
use App\Models\Customer;
use App\Models\LeasingApplicationActivity;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingProvider;
use App\Models\User;
use App\Services\Orders\IdempotentCheckoutService;
use App\Services\Payments\Providers\CardPaymentProvider;
use App\Services\Shipping\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\Fakes\FakeCardPaymentProvider;
use Tests\TestCase;
use Throwable;

class CheckoutIdempotencyMysqlConcurrencyTest extends TestCase
{
    public function test_mysql_checkout_serializes_same_and_different_keys_and_payloads(): void
    {
        $this->requireMysqlConcurrency();
        $stateBefore = $this->sharedDatabaseState();
        $referenceFixtures = [];

        try {
            $referenceFixtures = $this->createReferenceFixtures('serialization');

            $sameKey = $this->runScenario('same-key', [
                ['key' => 'same-key', 'overrides' => []],
                ['key' => 'same-key', 'overrides' => []],
            ], $referenceFixtures);
            $this->assertSame([201, 201], $sameKey->pluck('status')->sort()->values()->all());
            $this->assertSame(2, $sameKey->where('status', 201)->count());
            $this->assertSame(1, $sameKey->pluck('order_number')->unique()->count());

            $differentKeys = $this->runScenario('different-keys', [
                ['key' => 'different-key-a', 'overrides' => []],
                ['key' => 'different-key-b', 'overrides' => []],
            ], $referenceFixtures);
            $this->assertSame([201, 201], $differentKeys->pluck('status')->sort()->values()->all());
            $this->assertSame(2, $differentKeys->where('status', 201)->count());
            $this->assertSame(1, $differentKeys->pluck('order_number')->unique()->count());

            $differentPayloads = $this->runScenario('different-payloads', [
                ['key' => 'different-payload-a', 'overrides' => []],
                ['key' => 'different-payload-b', 'overrides' => ['notes' => 'Changed payload']],
            ], $referenceFixtures);
            $this->assertSame([201, 409], $differentPayloads->pluck('status')->sort()->values()->all());
            $this->assertSame(1, $differentPayloads->where('status', 201)->count());
            $this->assertSame(
                ['checkout_already_completed'],
                $differentPayloads->pluck('code')->filter()->values()->all(),
            );

            $rollbackRace = $this->runScenario('rollback-race', [
                ['key' => 'rollback-race-a', 'overrides' => [], 'fail' => true],
                ['key' => 'rollback-race-b', 'overrides' => [], 'wait_for_failure' => true],
            ], $referenceFixtures);
            $this->assertSame([201, 500], $rollbackRace->pluck('status')->sort()->values()->all());
            $this->assertSame(1, $rollbackRace->where('status', 201)->count());
            $this->assertSame(1, $rollbackRace->pluck('order_number')->filter()->unique()->count());
        } finally {
            $this->cleanupReferenceFixtures($referenceFixtures);
            $this->assertSame($stateBefore, $this->sharedDatabaseState());
        }
    }

    public function test_mysql_transaction_retry_commits_once_and_dispatches_once(): void
    {
        $this->requireMysqlConcurrency();
        $stateBefore = $this->sharedDatabaseState();
        $referenceFixtures = [];

        try {
            $referenceFixtures = $this->createReferenceFixtures('transaction-retry');
            Queue::fake([ConversionTrackingJob::class, SendEmailJob::class]);
            Event::fake([OrderCreated::class, OrderPaymentStatusChanged::class]);
            [$cart, $product, $stockBefore, $checkoutFixture] = $this->cartFixture(
                'transaction-retry',
                $referenceFixtures,
            );
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
                    $this->checkoutRequest($cart, 'transaction-retry', $checkoutFixture),
                    $this->checkoutPayload($checkoutFixture),
                );

                $this->assertSame(2, $attempts);
                $this->assertFalse($result->replayed());
                $this->assertScenarioEffects(
                    $cart,
                    $product,
                    $stockBefore,
                    1,
                    $checkoutFixture,
                );
                Queue::assertPushed(ConversionTrackingJob::class, 1);
                Queue::assertPushed(SendEmailJob::class, 1);
                Event::assertDispatched(OrderCreated::class, 1);
                Event::assertDispatched(OrderPaymentStatusChanged::class, 1);
            } finally {
                $this->cleanupScenario($cart, $product, $checkoutFixture);
            }
        } finally {
            $this->cleanupReferenceFixtures($referenceFixtures);
            $this->assertSame($stateBefore, $this->sharedDatabaseState());
        }
    }

    public function test_mysql_concurrent_leasing_checkout_creates_one_application_and_activity(): void
    {
        $this->requireMysqlConcurrency();
        config()->set('payments.methods.leasing.enabled', true);
        Event::fake([LeasingApplicationSubmitted::class]);
        $stateBefore = $this->sharedDatabaseState();
        $referenceFixtures = [];

        try {
            $referenceFixtures = $this->createReferenceFixtures(
                'leasing-concurrency',
                'leasing',
            );
            $results = $this->runScenario('leasing-concurrency', [
                ['key' => 'leasing-concurrency-a', 'overrides' => []],
                ['key' => 'leasing-concurrency-b', 'overrides' => []],
            ], $referenceFixtures);

            $this->assertSame([201, 201], $results->pluck('status')->sort()->values()->all());
            $this->assertSame(1, $results->pluck('order_number')->unique()->count());
        } finally {
            $this->cleanupReferenceFixtures($referenceFixtures);
            $this->assertSame($stateBefore, $this->sharedDatabaseState());
        }
    }

    public function test_mysql_authenticated_checkout_creates_one_customer_snapshot(): void
    {
        $this->requireMysqlConcurrency();
        $stateBefore = $this->sharedDatabaseState();
        $referenceFixtures = [];

        try {
            $referenceFixtures = $this->createReferenceFixtures('authenticated-snapshot');
            $results = $this->runScenario('authenticated-snapshot', [
                ['key' => 'authenticated-snapshot-a', 'overrides' => []],
                ['key' => 'authenticated-snapshot-b', 'overrides' => []],
            ], $referenceFixtures, authenticated: true);

            $this->assertSame([201, 201], $results->pluck('status')->sort()->values()->all());
            $this->assertSame(1, $results->pluck('order_number')->unique()->count());
        } finally {
            $this->cleanupReferenceFixtures($referenceFixtures);
            $this->assertSame($stateBefore, $this->sharedDatabaseState());
        }
    }

    public function test_mysql_controlled_fake_card_checkout_creates_one_customer_snapshot(): void
    {
        $this->requireMysqlConcurrency();
        config()->set('payments.methods.card.enabled', true);
        $this->app->instance(CardPaymentProvider::class, new FakeCardPaymentProvider);
        $stateBefore = $this->sharedDatabaseState();
        $referenceFixtures = [];

        try {
            $referenceFixtures = $this->createReferenceFixtures('card-snapshot', 'card');
            $results = $this->runScenario('card-snapshot', [
                ['key' => 'card-snapshot-a', 'overrides' => []],
                ['key' => 'card-snapshot-b', 'overrides' => []],
            ], $referenceFixtures);

            $this->assertSame([201, 201], $results->pluck('status')->sort()->values()->all());
            $this->assertSame(1, $results->pluck('order_number')->unique()->count());
        } finally {
            $this->cleanupReferenceFixtures($referenceFixtures);
            $this->assertSame($stateBefore, $this->sharedDatabaseState());
        }
    }

    private function runScenario(
        string $name,
        array $attempts,
        array $referenceFixtures,
        bool $authenticated = false,
    ) {
        [$cart, $product, $stockBefore, $checkoutFixture] = $this->cartFixture(
            $name,
            $referenceFixtures,
            $authenticated,
        );
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'checkout-idempotency-'.Str::uuid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $failureBarrier = $directory.DIRECTORY_SEPARATOR.'failure-barrier';
        $children = [];
        $waited = [];

        $this->assertNoOpenTransactions();
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
                        $checkoutFixture,
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
            $successfulResponses = $results->where('status', 201)->count();
            $safeChildResults = $results
                ->map(fn (array $result): array => [
                    'status' => $result['status'] ?? null,
                    'code' => $result['code'] ?? null,
                ])
                ->values()
                ->all();

            $this->assertGreaterThan(
                0,
                $successfulResponses,
                "Child results:\n".json_encode($safeChildResults, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
            $this->assertScenarioEffects(
                $cart,
                $product,
                $stockBefore,
                $successfulResponses,
                $checkoutFixture,
            );

            return $results;
        } finally {
            if (! file_exists($startFile)) {
                touch($startFile);
            }

            foreach (array_diff($children, $waited) as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $this->purgeDatabaseConnections();
            $this->cleanupScenario($cart, $product, $checkoutFixture);

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
        array $checkoutFixture,
        string $startFile,
        string $failureBarrier,
        string $directory,
    ): never {
        while (! file_exists($startFile)) {
            usleep(1_000);
        }

        $this->purgeDatabaseConnections();
        Auth::forgetGuards();

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
        $payload = $this->checkoutPayload($checkoutFixture, $overrides);

        try {
            if ($checkoutFixture['user_id'] !== null) {
                Auth::guard('sanctum')->setUser(
                    User::query()->findOrFail($checkoutFixture['user_id']),
                );
            }

            $cart = Cart::query()->findOrFail($cartId);
            $result = app(IdempotentCheckoutService::class)->checkout(
                $this->checkoutRequest(
                    $cart,
                    $attempt['key'],
                    $checkoutFixture,
                    $overrides,
                    $cartSession,
                ),
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
        Auth::forgetGuards();
        $this->purgeDatabaseConnections();

        exit($written === false ? 1 : 0);
    }

    private function assertScenarioEffects(
        Cart $cart,
        Product $product,
        int $stockBefore,
        int $expectedCapabilityCount,
        array $checkoutFixture,
    ): void {
        $record = CheckoutIdempotencyRecord::query()->where('cart_id', $cart->id)->sole();
        $this->assertSame('completed', $record->status);
        $this->assertNotNull($record->order_id);
        $this->assertSame(1, Order::query()->whereKey($record->order_id)->count());
        $order = Order::query()->findOrFail($record->order_id);
        $this->assertNotNull($order->customer_id);
        $this->assertSame(
            1,
            Customer::query()
                ->whereKey($order->customer_id)
                ->where('email', $checkoutFixture['customer_email'])
                ->count(),
        );
        $this->assertSame(
            1,
            Customer::query()->where('email', $checkoutFixture['customer_email'])->count(),
        );
        $this->assertSame($checkoutFixture['user_id'], $order->user_id);
        if ($checkoutFixture['user_id'] !== null) {
            $this->assertSame(
                $checkoutFixture['user_attributes'],
                User::query()->findOrFail($checkoutFixture['user_id'])->getAttributes(),
            );
        }
        $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $record->order_id)->count());
        $this->assertSame(1, DB::table('order_shipments')->where('order_id', $record->order_id)->count());
        $isLeasing = $checkoutFixture['payment_method'] === 'leasing';
        $this->assertSame(
            $isLeasing ? 1 : 0,
            DB::table('leasing_applications')->where('order_id', $record->order_id)->count(),
        );
        if ($isLeasing) {
            $applicationId = DB::table('leasing_applications')
                ->where('order_id', $record->order_id)
                ->value('id');
            $this->assertSame(
                1,
                DB::table('leasing_application_activities')
                    ->where('leasing_application_id', $applicationId)
                    ->where('event_type', LeasingApplicationActivity::EVENT_SUBMITTED)
                    ->count(),
            );
        }
        $capabilities = DB::table('checkout_confirmation_capabilities')
            ->where('order_id', $record->order_id)
            ->get(['order_id', 'token_hash']);
        $this->assertCount($expectedCapabilityCount, $capabilities);
        $this->assertSame(
            $expectedCapabilityCount,
            $capabilities->pluck('token_hash')->unique()->count(),
        );
        $this->assertSame(
            [$record->order_id],
            $capabilities->pluck('order_id')->unique()->values()->all(),
        );
        $this->assertFalse(Schema::hasColumn('checkout_confirmation_capabilities', 'token'));
        $this->assertSame($stockBefore - 1, (int) $product->fresh()->quantity);
        $this->assertSame('converted', $cart->fresh()->status);
    }

    private function cartFixture(
        string $name,
        array $referenceFixtures,
        bool $authenticated = false,
    ): array {
        $suffix = Str::lower(Str::random(12));
        $product = null;
        $cart = null;
        $user = null;

        try {
            if ($authenticated) {
                $user = User::factory()->create([
                    'email' => "checkout-account-{$suffix}@example.test",
                ]);
            }

            $product = Product::factory()->create([
                'category_id' => $referenceFixtures['category_id'],
                'brand_id' => null,
                'supplier_id' => null,
                'sku' => 'TEST-CHECKOUT-IDEMPOTENCY-'.Str::upper($suffix),
                'supplier_sku' => null,
                'name' => 'Checkout idempotency test product '.$suffix,
                'slug' => 'checkout-idempotency-test-product-'.$suffix,
                'active' => true,
                'workflow_status' => Product::WORKFLOW_PUBLISHED,
                'product_status' => 'active',
                'published_at' => now(),
                'price' => 100,
                'regular_price' => 100,
                'promo_price' => null,
                'quantity' => 100,
                'reserved_quantity' => 0,
                'stock_status' => Product::STOCK_STATUS_IN_STOCK,
                'availability_status_id' => null,
            ]);
            $cart = Cart::query()->create([
                'session_id' => $this->cartSession('mysql-'.$name.'-'.$suffix),
                'user_id' => $user?->getKey(),
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
            $checkoutFixture = [
                'customer_email' => "checkout-idempotency-{$suffix}@example.test",
                'payment_method' => $referenceFixtures['payment_method_code'],
                'shipping_method' => $referenceFixtures['shipping_method_code'],
                'shipping_provider' => $referenceFixtures['shipping_provider_code'],
                'user_id' => $user?->getKey(),
                'user_attributes' => $user?->getAttributes(),
            ];

            return [$cart, $product, 100, $checkoutFixture];
        } catch (Throwable $exception) {
            if ($cart !== null) {
                Cart::query()->whereKey($cart->id)->delete();
            }

            if ($product !== null) {
                Product::withTrashed()->whereKey($product->id)->forceDelete();
            }

            if ($user !== null) {
                User::withTrashed()->whereKey($user->id)->forceDelete();
            }

            throw $exception;
        }
    }

    private function checkoutRequest(
        Cart $cart,
        string $key,
        array $checkoutFixture,
        array $overrides = [],
        ?string $cartSession = null,
    ): Request {
        $request = Request::create(
            '/api/v1/checkout',
            'POST',
            $this->checkoutPayload($checkoutFixture, $overrides),
        );
        $request->headers->set('X-Cart-Session', $cartSession ?? $cart->session_id);
        $request->headers->set('Idempotency-Key', $this->checkoutIdempotencyKey($key));
        if ($checkoutFixture['user_id'] !== null) {
            $userId = $checkoutFixture['user_id'];
            $request->setUserResolver(
                fn (?string $guard = null): ?User => User::query()->find($userId),
            );
        }

        return $request;
    }

    private function checkoutPayload(array $checkoutFixture, array $overrides = []): array
    {
        $payload = [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => $checkoutFixture['customer_email'],
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => $checkoutFixture['payment_method'],
            'shipping_method' => $checkoutFixture['shipping_method'],
            'shipping_provider' => $checkoutFixture['shipping_provider'],
            'delivery_type' => 'address',
            'city' => 'Sofia',
            'notes' => 'Concurrent checkout',
            'terms' => true,
        ];

        if ($checkoutFixture['payment_method'] === 'leasing') {
            $payload['leasing_application'] = [
                'term_months' => 24,
                'down_payment' => '0.00',
                'contact_method' => 'phone',
                'contact_time' => 'anytime',
                'note' => null,
                'consent' => true,
            ];
        }

        return array_replace_recursive($payload, $overrides);
    }

    private function createReferenceFixtures(
        string $name,
        string $paymentMethodCode = 'cash_on_delivery',
    ): array {
        $suffix = Str::lower(Str::random(12));
        $fixtures = [
            'category_id' => null,
            'payment_provider_id' => null,
            'payment_method_id' => null,
            'payment_method_owned' => false,
            'payment_method_code' => $paymentMethodCode,
            'payment_method_previous_status' => null,
            'payment_provider_previous_status' => null,
            'shipping_provider_id' => null,
            'shipping_method_id' => null,
            'shipping_provider_code' => 'ci-shipping-'.$suffix,
            'shipping_method_code' => 'ci-address-'.$suffix,
        ];

        try {
            $category = Category::factory()->create([
                'name' => 'Checkout idempotency '.$name.' '.$suffix,
                'slug' => 'checkout-idempotency-'.$name.'-'.$suffix,
                'is_active' => true,
            ]);
            $fixtures['category_id'] = $category->id;

            $paymentMethod = PaymentMethod::query()
                ->with('provider')
                ->where('code', $fixtures['payment_method_code'])
                ->first();

            if ($paymentMethod === null) {
                $paymentProvider = PaymentProvider::query()->create([
                    'name' => 'Checkout idempotency payment '.$suffix,
                    'code' => 'ci-payment-'.$suffix,
                    'status' => 'active',
                    'settings' => ['test_owned' => true],
                ]);
                $fixtures['payment_provider_id'] = $paymentProvider->id;
                $paymentMethod = $paymentProvider->methods()->create([
                    'name' => 'Checkout idempotency cash on delivery',
                    'code' => $fixtures['payment_method_code'],
                    'type' => 'offline',
                    'status' => 'active',
                    'settings' => ['test_owned' => true],
                ]);
                $fixtures['payment_method_owned'] = true;
            } else {
                $fixtures['payment_method_previous_status'] = $paymentMethod->status;
                $fixtures['payment_provider_previous_status'] = $paymentMethod->provider?->status;

                if ($paymentMethod->status !== 'active') {
                    $paymentMethod->update(['status' => 'active']);
                }

                if ($paymentMethod->provider !== null) {
                    if ($paymentMethod->provider->status !== 'active') {
                        $paymentMethod->provider->update(['status' => 'active']);
                    }
                }
            }

            $fixtures['payment_method_id'] = $paymentMethod->id;
            $shippingProvider = ShippingProvider::query()->create([
                'name' => 'Checkout idempotency shipping '.$suffix,
                'code' => $fixtures['shipping_provider_code'],
                'status' => 'active',
                'settings' => ['test_owned' => true],
            ]);
            $fixtures['shipping_provider_id'] = $shippingProvider->id;
            $shippingMethod = $shippingProvider->methods()->create([
                'name' => 'Checkout idempotency address delivery',
                'code' => $fixtures['shipping_method_code'],
                'type' => 'address',
                'status' => 'active',
                'price' => 8.99,
                'free_shipping_threshold' => null,
                'settings' => ['test_owned' => true],
            ]);
            $fixtures['shipping_method_id'] = $shippingMethod->id;

            return $fixtures;
        } catch (Throwable $exception) {
            $this->cleanupReferenceFixtures($fixtures);

            throw $exception;
        }
    }

    private function cleanupScenario(
        Cart $cart,
        Product $product,
        array $checkoutFixture,
    ): void {
        $this->purgeDatabaseConnections();
        $orderIds = DB::table('orders')
            ->where('customer_email', $checkoutFixture['customer_email'])
            ->pluck('id');

        DB::table('checkout_idempotency_records')
            ->where('cart_id', $cart->id)
            ->orWhereIn('order_id', $orderIds)
            ->delete();
        DB::table('promotion_redemptions')
            ->where('session_id', $cart->session_id)
            ->orWhereIn('order_id', $orderIds)
            ->delete();
        DB::table('abandoned_cart_records')
            ->where('session_id', $cart->session_id)
            ->orWhere('restored_cart_id', $cart->id)
            ->orWhereIn('recovered_order_id', $orderIds)
            ->delete();
        DB::table('marketing_events')
            ->where('session_id', $cart->session_id)
            ->orWhere('payload->email', $checkoutFixture['customer_email'])
            ->delete();
        DB::table('email_logs')
            ->where('email', $checkoutFixture['customer_email'])
            ->delete();
        DB::table('conversion_logs')->whereIn('order_id', $orderIds)->delete();
        DB::table('erp_documents')->whereIn('order_id', $orderIds)->delete();
        DB::table('erp_sync_jobs')
            ->whereIn('entity_type', ['order', 'payment'])
            ->whereIn('entity_id', $orderIds)
            ->delete();
        DB::table('leasing_application_activities')
            ->whereIn(
                'leasing_application_id',
                DB::table('leasing_applications')->whereIn('order_id', $orderIds)->pluck('id'),
            )
            ->delete();
        DB::table('leasing_applications')->whereIn('order_id', $orderIds)->delete();
        Order::query()->whereKey($orderIds)->delete();
        Customer::query()
            ->where('email', $checkoutFixture['customer_email'])
            ->delete();
        Cart::query()->whereKey($cart->id)->delete();
        Product::withTrashed()->whereKey($product->id)->forceDelete();
        if ($checkoutFixture['user_id'] !== null) {
            User::withTrashed()->whereKey($checkoutFixture['user_id'])->forceDelete();
        }

        $this->assertSame(0, DB::table('checkout_idempotency_records')->where('cart_id', $cart->id)->count());
        $this->assertSame(0, DB::table('checkout_confirmation_capabilities')->whereIn('order_id', $orderIds)->count());
        $this->assertSame(0, DB::table('orders')->whereIn('id', $orderIds)->count());
        $this->assertSame(0, DB::table('customers')->where('email', $checkoutFixture['customer_email'])->count());
        $this->assertSame(0, DB::table('carts')->where('id', $cart->id)->count());
        $this->assertSame(0, DB::table('products')->where('id', $product->id)->count());
        $this->purgeDatabaseConnections();
    }

    private function cleanupReferenceFixtures(array $fixtures): void
    {
        $this->purgeDatabaseConnections();

        if (($fixtures['shipping_method_id'] ?? null) !== null) {
            ShippingMethod::query()->whereKey($fixtures['shipping_method_id'])->delete();
        }

        if (($fixtures['shipping_provider_id'] ?? null) !== null) {
            ShippingProvider::query()->whereKey($fixtures['shipping_provider_id'])->delete();
        }

        if (($fixtures['payment_method_owned'] ?? false) === true) {
            PaymentMethod::query()->whereKey($fixtures['payment_method_id'])->delete();
        } elseif (($fixtures['payment_method_id'] ?? null) !== null) {
            PaymentMethod::query()
                ->whereKey($fixtures['payment_method_id'])
                ->update(['status' => $fixtures['payment_method_previous_status']]);

            $paymentMethod = PaymentMethod::query()
                ->with('provider')
                ->find($fixtures['payment_method_id']);
            if (
                $paymentMethod?->provider !== null
                && ($fixtures['payment_provider_previous_status'] ?? null) !== null
            ) {
                $paymentMethod->provider->update([
                    'status' => $fixtures['payment_provider_previous_status'],
                ]);
            }
        }

        if (($fixtures['payment_provider_id'] ?? null) !== null) {
            PaymentProvider::query()->whereKey($fixtures['payment_provider_id'])->delete();
        }

        if (($fixtures['category_id'] ?? null) !== null) {
            Category::withTrashed()->whereKey($fixtures['category_id'])->forceDelete();
        }

        foreach ([
            'shipping_methods' => $fixtures['shipping_method_id'] ?? null,
            'shipping_providers' => $fixtures['shipping_provider_id'] ?? null,
            'payment_providers' => $fixtures['payment_provider_id'] ?? null,
            'categories' => $fixtures['category_id'] ?? null,
        ] as $table => $id) {
            if ($id !== null) {
                $this->assertSame(0, DB::table($table)->where('id', $id)->count());
            }
        }

        if (($fixtures['payment_method_owned'] ?? false) === true) {
            $this->assertSame(
                0,
                DB::table('payment_methods')
                    ->where('id', $fixtures['payment_method_id'])
                    ->count(),
            );
        }

        $this->purgeDatabaseConnections();
    }

    private function sharedDatabaseState(): array
    {
        $this->purgeDatabaseConnections();

        $tables = [
            'users',
            'categories',
            'products',
            'carts',
            'cart_items',
            'customers',
            'orders',
            'order_items',
            'payment_providers',
            'payment_methods',
            'payment_transactions',
            'leasing_applications',
            'leasing_application_activities',
            'shipping_providers',
            'shipping_methods',
            'order_shipments',
            'checkout_idempotency_records',
            'checkout_confirmation_capabilities',
            'promotion_redemptions',
            'abandoned_cart_records',
            'marketing_events',
            'email_logs',
            'conversion_logs',
            'erp_sync_jobs',
            'erp_documents',
        ];
        $state = collect($tables)
            ->mapWithKeys(fn (string $table): array => [
                $table => (int) DB::table($table)->count(),
            ])
            ->all();

        return [
            'admin_count' => (int) DB::table('users')
                ->where('email', 'admin@mycomputer.bg')
                ->count(),
            'migration_count' => (int) DB::table('migrations')->count(),
            'migration_max_batch' => (int) DB::table('migrations')->max('batch'),
        ] + $state;
    }

    private function assertNoOpenTransactions(): void
    {
        foreach (array_keys(DB::getConnections()) as $connectionName) {
            $this->assertSame(0, DB::connection($connectionName)->transactionLevel());
        }
    }

    private function purgeDatabaseConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $connectionName) {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
        }

        DB::purge(config('database.default'));
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
