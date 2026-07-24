<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
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
use Tests\TestCase;
use Throwable;

class CartLifecycleMysqlConcurrencyTest extends TestCase
{
    public function test_mysql_concurrent_resolution_converges_user_carts_and_guest_login(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for MySQL concurrency validation.');
        }

        $migrationCountBefore = (int) DB::table('migrations')->count();
        $latestMigrationBatchBefore = (int) DB::table('migrations')->max('batch');

        $this->runScenario(guestTarget: false);
        $this->runScenario(guestTarget: true);
        $this->assertSharedSchemaIntact($migrationCountBefore, $latestMigrationBatchBefore);
    }

    private function runScenario(bool $guestTarget): void
    {
        $scenario = $guestTarget ? 'guest-login' : 'two-user-carts';
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cart-lifecycle-'.$scenario.'-'.Str::uuid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];
        $waitedChildren = [];
        $userId = null;
        $productIds = [];
        $bundleId = null;
        $cartIds = [];
        $itemIds = [];
        $bundleItemIds = [];

        try {
            $user = User::factory()->create();
            $userId = $user->id;
            $products = Product::factory()->count(2)->create([
                'category_id' => null,
                'brand_id' => null,
                'supplier_id' => null,
            ]);
            $productIds = $products->modelKeys();
            $bundle = ProductBundle::query()->create([
                'name' => 'MySQL Lifecycle Test Bundle',
                'slug' => 'mysql-lifecycle-'.$scenario.'-'.Str::lower(Str::random(8)),
                'status' => 'active',
                'type' => 'fixed_bundle',
                'pricing_type' => 'fixed_price',
                'fixed_price' => 25,
            ]);
            $bundleId = $bundle->id;
            $firstCart = Cart::query()->create([
                'session_id' => $this->cartSession('mysql-lifecycle-'.$scenario.'-first'),
                'user_id' => $guestTarget ? null : $user->id,
                'coupon_code' => 'SHARED',
                'status' => CartStatus::Active->value,
                'expires_at' => now()->addDays(14),
            ]);
            $secondCart = Cart::query()->create([
                'session_id' => $this->cartSession('mysql-lifecycle-'.$scenario.'-second'),
                'user_id' => $user->id,
                'coupon_code' => 'SHARED',
                'status' => CartStatus::Active->value,
                'expires_at' => now()->addDays(14),
            ]);
            $cartIds = [$firstCart->id, $secondCart->id];

            foreach ([$firstCart, $secondCart] as $index => $cart) {
                $itemIds[] = $cart->items()->create([
                    'product_id' => $products[$index]->id,
                    'quantity' => $index + 1,
                    'unit_price' => 50 + $index,
                    'total_price' => (50 + $index) * ($index + 1),
                ])->id;
                $bundleItemIds[] = $cart->bundleItems()->create([
                    'product_bundle_id' => $bundle->id,
                    'selected_items' => [['slot' => $index]],
                    'quantity' => 1,
                    'unit_price' => 25 + $index,
                    'total_price' => 25 + $index,
                ])->id;
            }

            $sessions = [$firstCart->session_id, $secondCart->session_id];

            unset($user, $products, $bundle, $firstCart, $secondCart);

            foreach (array_keys(DB::getConnections()) as $connectionName) {
                $this->assertSame(0, DB::connection($connectionName)->transactionLevel());
            }

            $this->purgeDatabaseConnections();
            gc_collect_cycles();

            if (! mkdir($directory)) {
                throw new RuntimeException('Unable to create Cart lifecycle synchronization directory.');
            }

            foreach ($sessions as $index => $sessionId) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork Cart lifecycle test process.');
                }

                if ($pid === 0) {
                    $this->runResolver(
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

                $this->assertFileExists($resultFile, "Resolver {$index} did not write a result.");
                $contents = file_get_contents($resultFile);
                $this->assertNotFalse($contents);

                return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            });

            $this->assertSame([200, 200], $results->pluck('status')->sort()->values()->all());

            $this->purgeDatabaseConnections();
            $parent = DB::connection();
            $activeIds = $parent->table('carts')
                ->whereIn('id', $cartIds)
                ->where('user_id', $userId)
                ->where('status', CartStatus::Active->value)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('id');
            $mergedIds = $parent->table('carts')
                ->whereIn('id', $cartIds)
                ->where('status', CartStatus::Merged->value)
                ->pluck('id');

            $this->assertCount(1, $activeIds);
            $this->assertCount(1, $mergedIds);
            $this->assertSame('SHARED', $parent->table('carts')->where('id', $activeIds->first())->value('coupon_code'));
            $this->assertSame(2, $parent->table('cart_items')->whereIn('id', $itemIds)->count());
            $this->assertSame(3, (int) $parent->table('cart_items')->whereIn('id', $itemIds)->sum('quantity'));
            $this->assertSame(2, $parent->table('cart_bundle_items')->whereIn('id', $bundleItemIds)->count());
            $this->assertSame(2, (int) $parent->table('cart_bundle_items')->whereIn('id', $bundleItemIds)->sum('quantity'));
            $this->assertSame(0, $parent->table('cart_items')->where('cart_id', $mergedIds->first())->count());
            $this->assertSame(0, $parent->table('cart_bundle_items')->where('cart_id', $mergedIds->first())->count());
            $this->assertSame(2, $parent->table('carts')->whereIn('id', $cartIds)->distinct()->count('session_id'));

            if ($guestTarget) {
                $this->assertSame($cartIds[0], (int) $activeIds->first());
            } else {
                $this->assertSame(1, $results->pluck('cart_id')->unique()->count());
            }
        } finally {
            if (is_dir($directory) && ! file_exists($startFile)) {
                touch($startFile);
            }

            foreach (array_diff($children, $waitedChildren) as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $this->purgeDatabaseConnections();
            $cleanup = DB::connection();

            if ($bundleItemIds !== []) {
                $cleanup->table('cart_bundle_items')->whereIn('id', $bundleItemIds)->delete();
            }

            if ($itemIds !== []) {
                $cleanup->table('cart_items')->whereIn('id', $itemIds)->delete();
            }

            if ($cartIds !== []) {
                $cleanup->table('carts')->whereIn('id', $cartIds)->delete();
            }

            if ($bundleId !== null) {
                $cleanup->table('product_bundles')->where('id', $bundleId)->delete();
            }

            if ($productIds !== []) {
                $cleanup->table('products')->whereIn('id', $productIds)->delete();
            }

            if ($userId !== null) {
                $cleanup->table('users')->where('id', $userId)->delete();
            }

            $this->purgeDatabaseConnections();

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function runResolver(
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
            $cart = app(CartContextResolver::class)->resolve($request);
            $result = ['status' => 200, 'cart_id' => $cart->id];
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Cart lifecycle child failed: '.get_class($exception).PHP_EOL);
            $result = ['status' => 500, 'cart_id' => null];
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
