<?php

namespace Tests\Feature;

use App\Exceptions\CartPromotionChangedException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Promotions\PromotionEngineService;
use App\Services\Promotions\PromotionRedemptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PromotionRedemptionMysqlConcurrencyTest extends TestCase
{
    public function test_mysql_promotion_limits_serialize_global_user_session_and_multi_promotion_consumption(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for MySQL concurrency validation.');
        }

        $this->assertTrue(
            Schema::hasTable('migrations'),
            'The MySQL concurrency suite requires the test schema to be migrated before php artisan test starts.',
        );

        foreach ([
            'carts',
            'orders',
            'promotions',
            'promotion_rules',
            'promotion_redemptions',
            'suppliers',
            'supplier_category_mappings',
        ] as $requiredTable) {
            $this->assertTrue(
                Schema::hasTable($requiredTable),
                "The MySQL concurrency suite requires the {$requiredTable} table.",
            );
        }

        $migrationCount = (int) DB::table('migrations')->count();
        $latestMigrationBatch = (int) DB::table('migrations')->max('batch');

        $this->runGlobalLimitScenario();
        $this->runPerUserLimitScenario();
        $this->runPerSessionLimitScenario();
        $this->runAllOrNothingScenario();
        $this->assertSharedSchemaIntact($migrationCount, $latestMigrationBatch);
    }

    private function runGlobalLimitScenario(): void
    {
        $fixture = $this->fixture('global', usageLimit: 1);

        try {
            $results = $this->forkConsumptionAttempts($fixture['attempts']);

            $this->assertSame([200, 409], $results->pluck('status')->sort()->values()->all());
            $this->purgeDatabaseConnections();
            $this->assertSame(
                1,
                DB::table('promotion_redemptions')
                    ->where('promotion_id', $fixture['promotion_ids'][0])
                    ->count(),
            );
            $this->assertSame(
                1,
                (int) DB::table('promotions')
                    ->where('id', $fixture['promotion_ids'][0])
                    ->value('usage_count'),
            );
        } finally {
            $this->cleanupFixture($fixture);
        }
    }

    private function runPerUserLimitScenario(): void
    {
        $user = User::factory()->create();
        $fixture = $this->fixture('per-user', user: $user);
        DB::table('promotion_rules')->insert([
            'promotion_id' => $fixture['promotion_ids'][0],
            'rule_type' => 'per_user_limit',
            'operator' => 'equals',
            'value' => json_encode(['value' => 1], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fixture['attempts'] = $this->attemptsFor($fixture['cart_ids'], $fixture['order_ids']);
        $fixture['user_ids'][] = $user->id;

        try {
            $results = $this->forkConsumptionAttempts($fixture['attempts']);

            $this->assertSame([200, 409], $results->pluck('status')->sort()->values()->all());
            $this->purgeDatabaseConnections();
            $this->assertSame(
                1,
                DB::table('promotion_redemptions')
                    ->where('promotion_id', $fixture['promotion_ids'][0])
                    ->where('user_id', $user->id)
                    ->count(),
            );
        } finally {
            $this->cleanupFixture($fixture);
        }
    }

    private function runPerSessionLimitScenario(): void
    {
        $fixture = $this->fixture('per-session');
        DB::table('promotion_rules')->insert([
            'promotion_id' => $fixture['promotion_ids'][0],
            'rule_type' => 'per_session_limit',
            'operator' => 'equals',
            'value' => json_encode(['value' => 1], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fixture['cart_ids'][1] = $fixture['cart_ids'][0];
        $fixture['attempts'] = $this->attemptsFor($fixture['cart_ids'], $fixture['order_ids']);

        try {
            $results = $this->forkConsumptionAttempts($fixture['attempts']);

            $this->assertSame([200, 409], $results->pluck('status')->sort()->values()->all());
            $this->purgeDatabaseConnections();
            $sessionId = DB::table('carts')->where('id', $fixture['cart_ids'][0])->value('session_id');
            $this->assertSame(
                1,
                DB::table('promotion_redemptions')
                    ->where('promotion_id', $fixture['promotion_ids'][0])
                    ->where('session_id', $sessionId)
                    ->count(),
            );
        } finally {
            $this->cleanupFixture($fixture);
        }
    }

    private function runAllOrNothingScenario(): void
    {
        $productA = Product::factory()->create([
            'category_id' => null,
            'brand_id' => null,
            'supplier_id' => null,
            'price' => 100,
            'quantity' => 20,
        ]);
        $productB = Product::factory()->create([
            'category_id' => null,
            'brand_id' => null,
            'supplier_id' => null,
            'price' => 100,
            'quantity' => 20,
        ]);
        $promotionA = $this->promotion('all-or-nothing-a');
        $promotionB = $this->promotion('all-or-nothing-b', usageLimit: 1);
        $promotionA->rules()->create([
            'rule_type' => 'product_id',
            'operator' => 'equals',
            'value' => ['value' => $productA->id],
        ]);
        $promotionB->rules()->create([
            'rule_type' => 'quantity_min',
            'operator' => 'gte',
            'value' => ['value' => 1],
        ]);
        $cartA = $this->cart('all-or-nothing-a', $productA);
        $cartB = $this->cart('all-or-nothing-b', $productB);
        $orderA = $this->order('all-or-nothing-a');
        $orderB = $this->order('all-or-nothing-b');
        $resultA = app(PromotionEngineService::class)->evaluate(
            $cartA->fresh(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']),
        );
        $resultB = app(PromotionEngineService::class)->evaluate(
            $cartB->fresh(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']),
        );
        $fixture = [
            'product_ids' => [$productA->id, $productB->id],
            'cart_ids' => [$cartA->id, $cartB->id],
            'order_ids' => [$orderA->id, $orderB->id],
            'promotion_ids' => [$promotionA->id, $promotionB->id],
            'user_ids' => [],
            'attempts' => [
                [
                    'cart_id' => $cartA->id,
                    'order_id' => $orderA->id,
                    'result' => $resultA,
                    'delay_us' => 150_000,
                ],
                [
                    'cart_id' => $cartB->id,
                    'order_id' => $orderB->id,
                    'result' => $resultB,
                    'delay_us' => 0,
                ],
            ],
        ];

        try {
            $results = $this->forkConsumptionAttempts($fixture['attempts']);

            $this->assertSame([200, 409], $results->pluck('status')->sort()->values()->all());
            $this->purgeDatabaseConnections();
            $this->assertSame(0, DB::table('promotion_redemptions')->where('order_id', $orderA->id)->count());
            $this->assertSame(0, (int) DB::table('promotions')->where('id', $promotionA->id)->value('usage_count'));
            $this->assertSame(1, DB::table('promotion_redemptions')->where('order_id', $orderB->id)->count());
            $this->assertSame(1, (int) DB::table('promotions')->where('id', $promotionB->id)->value('usage_count'));
        } finally {
            $this->cleanupFixture($fixture);
        }
    }

    private function fixture(string $name, ?int $usageLimit = null, ?User $user = null): array
    {
        $product = Product::factory()->create([
            'category_id' => null,
            'brand_id' => null,
            'supplier_id' => null,
            'price' => 100,
            'quantity' => 20,
        ]);
        $promotion = $this->promotion($name, $usageLimit);
        $carts = collect([0, 1])->map(
            fn (int $index): Cart => $this->cart("{$name}-{$index}", $product, $user),
        );
        $orders = collect([0, 1])->map(
            fn (int $index): Order => $this->order("{$name}-{$index}"),
        );

        return [
            'product_ids' => [$product->id],
            'cart_ids' => $carts->pluck('id')->all(),
            'order_ids' => $orders->pluck('id')->all(),
            'promotion_ids' => [$promotion->id],
            'user_ids' => [],
            'attempts' => $this->attemptsFor(
                $carts->pluck('id')->all(),
                $orders->pluck('id')->all(),
            ),
        ];
    }

    private function attemptsFor(array $cartIds, array $orderIds): array
    {
        return collect($cartIds)->map(function (int $cartId, int $index) use ($orderIds): array {
            $cart = Cart::query()
                ->with(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount'])
                ->findOrFail($cartId);

            return [
                'cart_id' => $cartId,
                'order_id' => $orderIds[$index],
                'result' => app(PromotionEngineService::class)->evaluate($cart),
                'delay_us' => 0,
            ];
        })->all();
    }

    private function forkConsumptionAttempts(array $attempts)
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'promotion-redemption-'.Str::uuid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];
        $waited = [];

        foreach (array_keys(DB::getConnections()) as $connectionName) {
            $this->assertSame(0, DB::connection($connectionName)->transactionLevel());
        }

        $this->purgeDatabaseConnections();

        if (! mkdir($directory)) {
            throw new RuntimeException('Unable to create Promotion synchronization directory.');
        }

        try {
            foreach ($attempts as $index => $attempt) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork Promotion redemption process.');
                }

                if ($pid === 0) {
                    $this->runConsumptionChild($index, $attempt, $startFile, $directory);
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

            return collect(array_keys($attempts))->map(function (int $index) use ($directory): array {
                $path = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                $this->assertFileExists($path);

                return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            });
        } finally {
            if (! file_exists($startFile)) {
                touch($startFile);
            }

            foreach (array_diff($children, $waited) as $pid) {
                pcntl_waitpid($pid, $status);
            }

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function runConsumptionChild(
        int $index,
        array $attempt,
        string $startFile,
        string $directory,
    ): never {
        while (! file_exists($startFile)) {
            usleep(1_000);
        }

        if ($attempt['delay_us'] > 0) {
            usleep($attempt['delay_us']);
        }

        $this->purgeDatabaseConnections();

        try {
            app(PromotionRedemptionService::class)->consume(
                Cart::query()->findOrFail($attempt['cart_id']),
                Order::query()->findOrFail($attempt['order_id']),
                $attempt['result'],
            );
            $result = ['status' => 200, 'code' => null];
        } catch (CartPromotionChangedException) {
            $result = ['status' => 409, 'code' => 'cart_promotion_changed'];
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Promotion redemption child failed: '.get_class($exception).PHP_EOL);
            $result = ['status' => 500, 'code' => get_class($exception)];
        }

        $written = file_put_contents(
            $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        $this->purgeDatabaseConnections();

        exit($written === false ? 1 : 0);
    }

    private function promotion(string $name, ?int $usageLimit = null): Promotion
    {
        $promotion = Promotion::query()->create([
            'name' => 'MySQL '.$name,
            'type' => 'fixed_discount',
            'status' => 'active',
            'priority' => 0,
            'usage_limit' => $usageLimit,
            'usage_count' => 0,
            'stackable' => true,
        ]);
        $promotion->actions()->create([
            'action_type' => 'fixed_discount',
            'configuration' => ['amount' => 10],
        ]);

        return $promotion;
    }

    private function cart(string $name, Product $product, ?User $user = null): Cart
    {
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession('mysql-promotion-'.$name),
            'user_id' => $user?->id,
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        return $cart;
    }

    private function order(string $name): Order
    {
        return Order::query()->create([
            'order_number' => 'MYSQL-PROMO-'.Str::upper(Str::random(10)),
            'customer_email' => "{$name}@example.test",
            'customer_phone' => '0888123456',
            'customer_name' => 'Concurrency Test',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 0,
            'discount_total' => 10,
            'grand_total' => 90,
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
        ]);
    }

    private function cleanupFixture(array $fixture): void
    {
        $this->purgeDatabaseConnections();
        $sessions = DB::table('carts')->whereIn('id', $fixture['cart_ids'])->pluck('session_id');
        DB::table('marketing_events')->whereIn('session_id', $sessions)->delete();
        DB::table('promotion_redemptions')->whereIn('promotion_id', $fixture['promotion_ids'])->delete();
        DB::table('order_items')->whereIn('order_id', $fixture['order_ids'])->delete();
        DB::table('orders')->whereIn('id', $fixture['order_ids'])->delete();
        DB::table('cart_items')->whereIn('cart_id', $fixture['cart_ids'])->delete();
        DB::table('cart_bundle_items')->whereIn('cart_id', $fixture['cart_ids'])->delete();
        DB::table('carts')->whereIn('id', $fixture['cart_ids'])->delete();
        DB::table('promotion_actions')->whereIn('promotion_id', $fixture['promotion_ids'])->delete();
        DB::table('promotion_rules')->whereIn('promotion_id', $fixture['promotion_ids'])->delete();
        DB::table('promotions')->whereIn('id', $fixture['promotion_ids'])->delete();
        DB::table('products')->whereIn('id', $fixture['product_ids'])->delete();

        if ($fixture['user_ids'] !== []) {
            DB::table('users')->whereIn('id', $fixture['user_ids'])->delete();
        }

        $this->purgeDatabaseConnections();
    }

    private function assertSharedSchemaIntact(int $migrationCount, int $latestMigrationBatch): void
    {
        $this->purgeDatabaseConnections();
        $this->assertSame($migrationCount, (int) DB::table('migrations')->count());
        $this->assertSame($latestMigrationBatch, (int) DB::table('migrations')->max('batch'));
        $this->assertTrue(Schema::hasColumn('suppliers', 'import_enabled'));
        $this->assertTrue(Schema::hasColumn('suppliers', 'msrp_strategy'));
        $this->assertTrue(Schema::hasTable('supplier_category_mappings'));

        $supplier = Supplier::factory()->create();
        $this->assertSame(
            0,
            DB::table('supplier_category_mappings')->where('supplier_id', $supplier->id)->count(),
        );
        $supplier->delete();
        $this->assertSame($migrationCount, (int) DB::table('migrations')->count());
        $this->assertSame($latestMigrationBatch, (int) DB::table('migrations')->max('batch'));
        $this->purgeDatabaseConnections();
    }

    private function purgeDatabaseConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $connectionName) {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
        }

        DB::purge(config('database.default'));
    }
}
