<?php

namespace Tests\Feature;

use App\Exceptions\CartRecoveryConsumedException;
use App\Models\AbandonedCartRecord;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Email\EmailMarketingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class AbandonedCartRecoveryMysqlConcurrencyTest extends TestCase
{
    public function test_mysql_recovery_token_restores_exactly_one_cart_under_concurrent_replay(): void
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
            'cart_items',
            'products',
            'abandoned_cart_records',
            'marketing_events',
            'suppliers',
            'supplier_category_mappings',
        ] as $requiredTable) {
            $this->assertTrue(
                Schema::hasTable($requiredTable),
                "The MySQL concurrency suite requires the {$requiredTable} table.",
            );
        }

        config()->set(
            'commerce.abandoned_cart_recovery.enabled',
            true,
        );
        $this->assertTrue(
            config('commerce.abandoned_cart_recovery.enabled') === true,
        );

        $migrationCount = (int) DB::table('migrations')->count();
        $latestMigrationBatch = (int) DB::table('migrations')->max('batch');
        $productId = null;
        $categoryId = null;
        $brandId = null;
        $originalCartId = null;
        $originalItemId = null;
        $recordId = null;
        $targetCartIds = [];
        $targetSessions = [
            $this->cartSession('mysql-recovery-target-a'),
            $this->cartSession('mysql-recovery-target-b'),
        ];

        try {
            $product = Product::factory()->create([
                'supplier_id' => null,
                'price' => 125,
                'regular_price' => 125,
                'promo_price' => null,
                'quantity' => 20,
            ]);
            $productId = $product->id;
            $categoryId = $product->category_id;
            $brandId = $product->brand_id;

            $this->assertTrue($product->isPubliclyVisible());
            $this->assertNotNull($product->category_id);
            $this->assertTrue($product->category()->where('is_active', true)->exists());
            $this->assertSame(Product::WORKFLOW_PUBLISHED, $product->workflow_status);
            $this->assertSame('active', $product->product_status);
            $this->assertTrue((bool) $product->active);
            $this->assertNotNull($product->published_at);
            $this->assertNotEmpty($product->slug);

            $original = Cart::query()->create([
                'session_id' => $this->cartSession('mysql-recovery-original'),
                'coupon_code' => 'ORIGINAL-UNCHANGED',
                'status' => 'active',
                'expires_at' => now()->addDays(14),
            ]);
            $originalCartId = $original->id;
            $originalItem = $original->items()->create([
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 125,
                'total_price' => 375,
            ]);
            $originalItemId = $originalItem->id;
            $record = AbandonedCartRecord::query()->create([
                'session_id' => $original->session_id,
                'email' => 'mysql-recovery@example.test',
                'cart_snapshot' => ['items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'is_gift' => false,
                    'unit_price' => 125,
                    'total_price' => 125,
                ]], 'subtotal' => 125],
                'cart_total' => 125,
                'items_count' => 1,
                'last_cart_activity_at' => now()->subHours(2),
                'recovery_token' => Str::random(64),
                'recovery_token_expires_at' => now()->addDay(),
                'status' => 'pending',
            ]);
            $recordId = $record->id;
            $token = $record->recovery_token;

            foreach (array_keys(DB::getConnections()) as $connectionName) {
                $this->assertSame(0, DB::connection($connectionName)->transactionLevel());
            }

            $results = $this->forkRestoreAttempts($token, $targetSessions);

            $this->assertSame(
                [200, 409],
                $results->pluck('status')->sort()->values()->all(),
                'Unexpected recovery child results: '.$results->toJson(),
            );
            $this->assertSame(
                ['cart_recovery_consumed'],
                $results->pluck('code')->filter()->values()->all(),
            );

            $this->purgeDatabaseConnections();
            $recordState = DB::table('abandoned_cart_records')->where('id', $recordId)->first();
            $this->assertNotNull($recordState);
            $this->assertSame('restored', $recordState->status);
            $this->assertNotNull($recordState->restored_at);
            $this->assertNotNull($recordState->restored_cart_id);

            $targetCartIds = DB::table('carts')
                ->whereIn('session_id', $targetSessions)
                ->pluck('id')
                ->all();
            $this->assertCount(1, $targetCartIds);
            $this->assertSame((int) $recordState->restored_cart_id, (int) $targetCartIds[0]);
            $this->assertSame(
                1,
                DB::table('cart_items')
                    ->where('cart_id', $targetCartIds[0])
                    ->where('product_id', $productId)
                    ->where('is_gift', false)
                    ->count(),
            );
            $this->assertSame('ORIGINAL-UNCHANGED', DB::table('carts')->where('id', $originalCartId)->value('coupon_code'));
            $this->assertSame(3, (int) DB::table('cart_items')->where('id', $originalItemId)->value('quantity'));
            $this->assertSame(
                1,
                DB::table('marketing_events')
                    ->where('event_name', 'abandoned_cart_restored')
                    ->where('payload->abandoned_cart_record_id', $recordId)
                    ->count(),
            );
        } finally {
            $this->purgeDatabaseConnections();
            $targetCartIds = DB::table('carts')
                ->whereIn('session_id', $targetSessions)
                ->pluck('id')
                ->all();

            if ($recordId !== null) {
                DB::table('marketing_events')
                    ->where('event_name', 'abandoned_cart_restored')
                    ->where('payload->abandoned_cart_record_id', $recordId)
                    ->delete();
                DB::table('abandoned_cart_records')->where('id', $recordId)->delete();
            }

            $cartIds = array_values(array_filter([
                $originalCartId,
                ...$targetCartIds,
            ]));

            if ($cartIds !== []) {
                DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
                DB::table('cart_bundle_items')->whereIn('cart_id', $cartIds)->delete();
                DB::table('carts')->whereIn('id', $cartIds)->delete();
            }

            if ($productId !== null) {
                DB::table('products')->where('id', $productId)->delete();
            }

            if ($brandId !== null) {
                DB::table('brands')->where('id', $brandId)->delete();
            }

            if ($categoryId !== null) {
                DB::table('categories')->where('id', $categoryId)->delete();
            }

            $this->assertSharedSchemaIntact($migrationCount, $latestMigrationBatch);
        }
    }

    private function forkRestoreAttempts(string $token, array $targetSessions)
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abandoned-recovery-'.Str::uuid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];
        $waited = [];

        $this->purgeDatabaseConnections();

        if (! mkdir($directory)) {
            throw new RuntimeException('Unable to create abandoned-Cart recovery synchronization directory.');
        }

        try {
            foreach ($targetSessions as $index => $sessionId) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork abandoned-Cart recovery process.');
                }

                if ($pid === 0) {
                    $this->runRestoreChild($index, $token, $sessionId, $startFile, $directory);
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

            return collect(array_keys($targetSessions))->map(function (int $index) use ($directory): array {
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

    private function runRestoreChild(
        int $index,
        string $token,
        string $sessionId,
        string $startFile,
        string $directory,
    ): never {
        while (! file_exists($startFile)) {
            usleep(1_000);
        }

        $this->purgeDatabaseConnections();

        try {
            $cart = app(EmailMarketingService::class)->restoreCartFromToken($token, $sessionId);
            $result = ['status' => 200, 'code' => null, 'cart_id' => $cart->id];
        } catch (CartRecoveryConsumedException) {
            $result = ['status' => 409, 'code' => 'cart_recovery_consumed', 'cart_id' => null];
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Abandoned-Cart recovery child failed: '.get_class($exception).PHP_EOL);
            $result = ['status' => 500, 'code' => get_class($exception), 'cart_id' => null];
        }

        $written = file_put_contents(
            $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        $this->purgeDatabaseConnections();

        exit($written === false ? 1 : 0);
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
