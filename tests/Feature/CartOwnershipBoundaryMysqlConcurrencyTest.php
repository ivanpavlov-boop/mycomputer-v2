<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Cart\CartContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class CartOwnershipBoundaryMysqlConcurrencyTest extends TestCase
{
    public function test_mysql_claim_uses_a_real_row_lock_and_rejects_the_losing_claimant(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for MySQL concurrency validation.');
        }

        $migrationCountBefore = (int) DB::table('migrations')->count();
        $latestMigrationBatchBefore = (int) DB::table('migrations')->max('batch');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cart-claim-'.Str::uuid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];
        $waitedChildren = [];
        $userIds = [];
        $productId = null;
        $bundleId = null;
        $cartId = null;
        $itemId = null;
        $bundleItemId = null;

        try {
            $users = User::factory()->count(2)->create();
            $userIds = $users->modelKeys();

            $product = Product::factory()->create([
                'category_id' => null,
                'brand_id' => null,
                'supplier_id' => null,
            ]);
            $productId = $product->id;

            $bundle = ProductBundle::query()->create([
                'name' => 'MySQL Ownership Test Bundle',
                'slug' => 'mysql-ownership-test-bundle-'.Str::lower(Str::random(8)),
                'status' => 'active',
                'type' => 'fixed_bundle',
                'pricing_type' => 'fixed_price',
                'fixed_price' => 25,
            ]);
            $bundleId = $bundle->id;

            $cart = Cart::query()->create([
                'session_id' => $this->cartSession('mysql-concurrent-claim'),
                'status' => 'active',
                'expires_at' => now()->addDays(14),
            ]);
            $cartId = $cart->id;

            $item = $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 50,
                'total_price' => 100,
            ]);
            $itemId = $item->id;

            $bundleItem = $cart->bundleItems()->create([
                'product_bundle_id' => $bundle->id,
                'selected_items' => [],
                'quantity' => 1,
                'unit_price' => 25,
                'total_price' => 25,
            ]);
            $bundleItemId = $bundleItem->id;

            $sessionId = $cart->session_id;

            unset($users, $product, $bundle, $cart, $item, $bundleItem);

            foreach (array_keys(DB::getConnections()) as $connectionName) {
                $this->assertSame(0, DB::connection($connectionName)->transactionLevel());
            }

            $this->purgeDatabaseConnections();
            gc_collect_cycles();

            if (! mkdir($directory)) {
                throw new RuntimeException('Unable to create Cart claim synchronization directory.');
            }

            foreach ($userIds as $index => $userId) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork Cart claim test process.');
                }

                if ($pid === 0) {
                    $this->runClaimant(
                        index: $index,
                        userId: $userId,
                        sessionId: $sessionId,
                        startFile: $startFile,
                        directory: $directory,
                    );
                }

                $children[] = $pid;
            }

            touch($startFile);

            foreach ($children as $pid) {
                $waitedPid = pcntl_waitpid($pid, $status);

                if ($waitedPid === $pid) {
                    $waitedChildren[] = $pid;
                }

                $this->assertSame($pid, $waitedPid);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $results = collect([0, 1])->map(function (int $index) use ($directory): array {
                $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";

                $this->assertFileExists($resultFile, "Claimant {$index} did not write a result.");
                $contents = file_get_contents($resultFile);
                $this->assertNotFalse($contents);

                return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            });

            $this->assertSame([200, 403], $results->pluck('status')->sort()->values()->all());

            $this->purgeDatabaseConnections();
            $parent = DB::connection();
            $ownerId = (int) $parent->table('carts')->where('id', $cartId)->value('user_id');

            $this->assertContains($ownerId, $userIds);
            $this->assertSame($ownerId, (int) $results->firstWhere('status', 200)['user_id']);
            $this->assertSame(1, $parent->table('carts')->where('session_id', $sessionId)->count());
            $this->assertSame(1, $parent->table('cart_items')->where('id', $itemId)->count());
            $this->assertSame(2, (int) $parent->table('cart_items')->where('id', $itemId)->value('quantity'));
            $this->assertSame(1, $parent->table('cart_bundle_items')->where('id', $bundleItemId)->count());
            $this->assertSame(1, (int) $parent->table('cart_bundle_items')->where('id', $bundleItemId)->value('quantity'));
        } finally {
            if (is_dir($directory) && ! file_exists($startFile)) {
                touch($startFile);
            }

            foreach (array_diff($children, $waitedChildren) as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $this->purgeDatabaseConnections();
            $cleanup = DB::connection();

            if ($bundleItemId !== null) {
                $cleanup->table('cart_bundle_items')->where('id', $bundleItemId)->delete();
            }

            if ($itemId !== null) {
                $cleanup->table('cart_items')->where('id', $itemId)->delete();
            }

            if ($cartId !== null) {
                $cleanup->table('carts')->where('id', $cartId)->delete();
            }

            if ($bundleId !== null) {
                $cleanup->table('product_bundles')->where('id', $bundleId)->delete();
            }

            if ($productId !== null) {
                $cleanup->table('products')->where('id', $productId)->delete();
            }

            if ($userIds !== []) {
                $cleanup->table('users')->whereIn('id', $userIds)->delete();
            }

            $this->purgeDatabaseConnections();

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }

            $this->assertSharedSchemaIntact($migrationCountBefore, $latestMigrationBatchBefore);
        }
    }

    private function runClaimant(
        int $index,
        int $userId,
        string $sessionId,
        string $startFile,
        string $directory,
    ): never {
        while (! file_exists($startFile)) {
            usleep(1_000);
        }

        $this->purgeDatabaseConnections();
        Auth::forgetGuards();

        try {
            Auth::guard('sanctum')->setUser(User::query()->findOrFail($userId));
            $request = Request::create('/api/v1/cart', 'GET', server: [
                'HTTP_X_CART_SESSION' => $sessionId,
            ]);
            app(CartContextResolver::class)->resolve($request);
            $result = ['status' => 200, 'user_id' => $userId];
        } catch (HttpExceptionInterface $exception) {
            $result = ['status' => $exception->getStatusCode(), 'user_id' => $userId];
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Cart claim child failed: '.get_class($exception).PHP_EOL);
            $result = ['status' => 500, 'user_id' => $userId];
        }

        $written = file_put_contents(
            $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        $this->purgeDatabaseConnections();

        exit($written === false ? 1 : 0);
    }

    private function assertSharedSchemaIntact(int $migrationCountBefore, int $latestMigrationBatchBefore): void
    {
        $this->purgeDatabaseConnections();

        $this->assertSame($migrationCountBefore, (int) DB::table('migrations')->count());
        $this->assertSame($latestMigrationBatchBefore, (int) DB::table('migrations')->max('batch'));
        $this->assertTrue(Schema::hasColumn('suppliers', 'import_enabled'));
        $this->assertTrue(Schema::hasColumn('suppliers', 'msrp_strategy'));
        $this->assertTrue(Schema::hasTable('supplier_category_mappings'));

        $supplierId = null;

        try {
            $supplier = Supplier::factory()->create();
            $supplierId = $supplier->id;

            $this->assertSame(
                0,
                DB::table('supplier_category_mappings')->where('supplier_id', $supplierId)->count(),
            );
        } finally {
            if ($supplierId !== null) {
                DB::table('suppliers')->where('id', $supplierId)->delete();
            }

            $this->assertSame($migrationCountBefore, (int) DB::table('migrations')->count());
            $this->assertSame($latestMigrationBatchBefore, (int) DB::table('migrations')->max('batch'));
            $this->purgeDatabaseConnections();
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
}
