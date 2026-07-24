<?php

namespace Tests\Feature;

use App\Exceptions\CartQuantityUnavailableException;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Supplier;
use App\Services\Cart\CartService;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CartItemMutationMysqlConcurrencyTest extends TestCase
{
    public function test_mysql_cart_item_mutations_are_serialized_and_preserve_paid_gift_identity(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for MySQL concurrency validation.');
        }

        $migrationCount = (int) DB::table('migrations')->count();
        $latestMigrationBatch = (int) DB::table('migrations')->max('batch');

        $this->runConcurrentAdds(initialQuantity: 0, expectedStatuses: [200, 200], expectedQuantity: 2);
        $this->runConcurrentAdds(initialQuantity: 98, expectedStatuses: [200, 409], expectedQuantity: 99);
        $this->runPaidGiftScenario();
        $this->assertSharedSchemaIntact($migrationCount, $latestMigrationBatch);
    }

    private function runConcurrentAdds(
        int $initialQuantity,
        array $expectedStatuses,
        int $expectedQuantity,
    ): void {
        $product = Product::factory()->create(['quantity' => 150, 'price' => 35]);
        $cart = $this->cart('concurrent-add-'.$initialQuantity);

        if ($initialQuantity > 0) {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $initialQuantity,
                'is_gift' => false,
                'unit_price' => $product->effectivePrice(),
                'total_price' => $product->effectivePrice() * $initialQuantity,
            ]);
        }

        try {
            $results = $this->forkMutations($cart->id, $product->id, ['add', 'add']);

            $this->assertSame($expectedStatuses, $results->pluck('status')->sort()->values()->all());
            $this->purgeDatabaseConnections();
            $paid = DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('is_gift', false)
                ->get();
            $this->assertCount(1, $paid);
            $this->assertSame($expectedQuantity, (int) $paid->first()->quantity);
            $this->assertSame(35.0, (float) $paid->first()->unit_price);
            $this->assertSame(35.0 * $expectedQuantity, (float) $paid->first()->total_price);
        } finally {
            $this->cleanup([$cart->id], [$product->id]);
        }
    }

    private function runPaidGiftScenario(): void
    {
        $product = Product::factory()->create(['quantity' => 150, 'price' => 40]);
        $cart = $this->cart('concurrent-paid-gift');
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'is_gift' => false,
            'unit_price' => 40,
            'total_price' => 40,
        ]);
        $promotion = Promotion::query()->create([
            'name' => 'Concurrent same-product gift',
            'type' => 'gift_product',
            'status' => 'active',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'stackable' => true,
        ]);
        $promotion->rules()->create([
            'rule_type' => 'product_id',
            'operator' => 'equals',
            'value' => ['value' => $product->id],
        ]);
        $promotion->actions()->create([
            'action_type' => 'gift_product',
            'configuration' => ['product_id' => $product->id, 'quantity' => 1],
        ]);

        try {
            $results = $this->forkMutations($cart->id, $product->id, ['add', 'gifts']);

            $this->assertSame([200, 200], $results->pluck('status')->sort()->values()->all());
            $this->purgeDatabaseConnections();
            $lines = DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->orderBy('is_gift')
                ->get();
            $this->assertCount(2, $lines);
            $this->assertSame(2, (int) $lines[0]->quantity);
            $this->assertSame(40.0, (float) $lines[0]->unit_price);
            $this->assertSame(1, (int) $lines[1]->quantity);
            $this->assertSame(0.0, (float) $lines[1]->unit_price);
            $this->assertSame($promotion->id, (int) $lines[1]->promotion_id);
        } finally {
            $this->purgeDatabaseConnections();
            DB::table('marketing_events')->where('session_id', $cart->session_id)->delete();
            Promotion::query()->whereKey($promotion->id)->delete();
            $this->cleanup([$cart->id], [$product->id]);
        }
    }

    private function forkMutations(int $cartId, int $productId, array $operations)
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cart-item-mutation-'.Str::uuid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];
        $waited = [];

        foreach (array_keys(DB::getConnections()) as $connectionName) {
            $this->assertSame(0, DB::connection($connectionName)->transactionLevel());
        }

        $this->purgeDatabaseConnections();

        if (! mkdir($directory)) {
            throw new RuntimeException('Unable to create Cart item mutation synchronization directory.');
        }

        try {
            foreach ($operations as $index => $operation) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork Cart item mutation test process.');
                }

                if ($pid === 0) {
                    $this->runChild($index, $operation, $cartId, $productId, $startFile, $directory);
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

            return collect(array_keys($operations))->map(function (int $index) use ($directory): array {
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

    private function runChild(
        int $index,
        string $operation,
        int $cartId,
        int $productId,
        string $startFile,
        string $directory,
    ): never {
        while (! file_exists($startFile)) {
            usleep(1_000);
        }

        $this->purgeDatabaseConnections();

        try {
            $cart = Cart::query()->findOrFail($cartId);

            if ($operation === 'add') {
                app(CartService::class)->add($cart, Product::query()->findOrFail($productId), 1);
            } else {
                app(PromotionEngineService::class)->applyAutomaticGifts($cart);
            }

            $result = ['status' => 200, 'code' => null];
        } catch (CartQuantityUnavailableException) {
            $result = ['status' => 409, 'code' => 'cart_quantity_unavailable'];
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Cart mutation child failed: '.get_class($exception).PHP_EOL);
            $result = ['status' => 500, 'code' => get_class($exception)];
        }

        $written = file_put_contents(
            $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        $this->purgeDatabaseConnections();

        exit($written === false ? 1 : 0);
    }

    private function cart(string $name): Cart
    {
        return Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
    }

    private function cleanup(array $cartIds, array $productIds): void
    {
        $this->purgeDatabaseConnections();
        DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
        DB::table('cart_bundle_items')->whereIn('cart_id', $cartIds)->delete();
        DB::table('carts')->whereIn('id', $cartIds)->delete();
        DB::table('products')->whereIn('id', $productIds)->delete();
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
