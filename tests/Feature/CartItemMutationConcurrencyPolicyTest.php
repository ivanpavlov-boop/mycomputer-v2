<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Cart\CartService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CartItemMutationConcurrencyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_and_gift_lines_have_distinct_database_identities(): void
    {
        $cart = $this->cart();
        $product = Product::factory()->create(['quantity' => 20]);

        $paid = $cart->items()->create($this->line($product, false, 2, 25));
        $gift = $cart->items()->create($this->line($product, true, 1, 0));

        $this->assertTrue($paid->isPaidLine());
        $this->assertTrue($gift->isGiftLine());
        $this->assertSame(1, $cart->items()->paid()->count());
        $this->assertSame(1, $cart->items()->gifts()->count());
        $this->assertTrue(Schema::hasIndex('cart_items', 'cart_items_cart_product_gift_unique', 'unique'));
        $this->assertFalse(Schema::hasIndex('cart_items', 'cart_items_cart_id_product_id_unique', 'unique'));
    }

    public function test_database_rejects_duplicate_paid_and_duplicate_gift_lines(): void
    {
        $cart = $this->cart();
        $paidProduct = Product::factory()->create();
        $giftProduct = Product::factory()->create();
        $cart->items()->create($this->line($paidProduct, false, 1, 10));
        $cart->items()->create($this->line($giftProduct, true, 1, 0));

        foreach ([
            $this->line($paidProduct, false, 1, 10),
            $this->line($giftProduct, true, 1, 0),
        ] as $duplicate) {
            try {
                $cart->items()->create($duplicate);
                $this->fail('Expected the Cart line identity constraint to reject a duplicate.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_add_targets_only_paid_line_when_same_product_gift_exists(): void
    {
        $cart = $this->cart();
        $product = Product::factory()->create(['quantity' => 20, 'price' => 30]);
        $promotion = $this->giftPromotion($product, 2);
        $gift = $cart->items()->create(
            $this->line($product, true, 2, 0) + ['promotion_id' => $promotion->id],
        );
        $giftSnapshot = $gift->only([
            'quantity',
            'is_gift',
            'promotion_id',
            'unit_price',
            'total_price',
        ]);

        app(CartService::class)->add($cart, $product, 3);
        app(CartService::class)->add($cart->fresh(), $product, 2);

        $paid = $cart->items()->paid()->where('product_id', $product->id)->sole();
        $this->assertSame(5, $paid->quantity);
        $this->assertSame($product->effectivePrice(), (float) $paid->unit_price);
        $this->assertSame($product->effectivePrice() * 5, (float) $paid->total_price);
        $this->assertSame($giftSnapshot, $gift->fresh()->only(array_keys($giftSnapshot)));
        $this->assertSame(2, $cart->items()->where('product_id', $product->id)->count());
        $this->assertSame(20, $product->fresh()->quantity);
    }

    public function test_paid_update_and_remove_do_not_mutate_same_product_gift(): void
    {
        $cart = $this->cart();
        $product = Product::factory()->create(['quantity' => 20]);
        $paid = $cart->items()->create($this->line($product, false, 2, 25));
        $promotion = $this->giftPromotion($product);
        $gift = $cart->items()->create(
            $this->line($product, true, 1, 0) + ['promotion_id' => $promotion->id],
        );
        $giftSnapshot = $gift->only(['quantity', 'is_gift', 'unit_price', 'total_price']);

        app(CartService::class)->update($cart, $paid, 4);

        $this->assertSame(4, $paid->fresh()->quantity);
        $this->assertSame($giftSnapshot, $gift->fresh()->only(array_keys($giftSnapshot)));

        app(CartService::class)->remove($cart->fresh(), $paid->fresh());

        $this->assertDatabaseMissing('cart_items', ['id' => $paid->id]);
        $this->assertSame($giftSnapshot, $gift->fresh()->only(array_keys($giftSnapshot)));
    }

    public function test_direct_gift_update_and_delete_are_stable_conflicts(): void
    {
        $cart = $this->cart('immutable-gift');
        $product = Product::factory()->create(['quantity' => 20]);
        $paid = $cart->items()->create($this->line($product, false, 2, 25));
        $gift = $cart->items()->create($this->line($product, true, 1, 0));
        $snapshot = $gift->fresh()->getAttributes();

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->patchJson("/api/v1/cart/items/{$gift->id}", ['quantity' => 2])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_gift_line_immutable')
            ->assertJsonPath('error.message', 'Automatic gift items cannot be changed directly.');

        $this->withHeader('X-Cart-Session', $cart->session_id)
            ->deleteJson("/api/v1/cart/items/{$gift->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_gift_line_immutable');

        $this->assertEquals($snapshot, $gift->fresh()->getAttributes());
        $this->assertDatabaseHas('cart_items', ['id' => $paid->id, 'is_gift' => false]);
    }

    public function test_clear_removes_paid_gift_and_bundle_lines(): void
    {
        $cart = $this->cart();
        $product = Product::factory()->create();
        $cart->items()->create($this->line($product, false, 1, 10));
        $cart->items()->create($this->line($product, true, 1, 0));

        app(CartService::class)->clear($cart);

        $this->assertSame(0, $cart->items()->count());
        $this->assertSame(0, $cart->bundleItems()->count());
    }

    public function test_migration_rollback_refuses_to_merge_paid_and_gift_data(): void
    {
        $cart = $this->cart();
        $product = Product::factory()->create();
        $cart->items()->create($this->line($product, false, 1, 10));
        $cart->items()->create($this->line($product, true, 1, 0));
        $migration = require database_path('migrations/2026_07_24_090000_change_cart_item_line_identity.php');

        try {
            $migration->down();
            $this->fail('Expected rollback to fail closed while paid and gift lines coexist.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cannot restore the legacy Cart item identity while paid and gift lines coexist.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(2, $cart->items()->where('product_id', $product->id)->count());
        $this->assertTrue(Schema::hasIndex('cart_items', 'cart_items_cart_product_gift_unique', 'unique'));
    }

    public function test_migration_round_trip_preserves_existing_paid_line_values(): void
    {
        $cart = $this->cart('migration-preserves-data');
        $product = Product::factory()->create();
        $paid = $cart->items()->create($this->line($product, false, 4, 17.5));
        $snapshot = $paid->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_07_24_090000_change_cart_item_line_identity.php');

        $migration->down();
        $this->assertTrue(Schema::hasIndex('cart_items', 'cart_items_cart_id_product_id_unique', 'unique'));

        $migration->up();

        $this->assertEquals($snapshot, $paid->fresh()->getAttributes());
        $this->assertTrue(Schema::hasIndex('cart_items', 'cart_items_cart_product_gift_unique', 'unique'));
    }

    public function test_mysql_cart_item_identity_keeps_foreign_keys_enforced(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL foreign key metadata is required for this regression test.');
        }

        $cart = $this->cart('mysql-foreign-key-integrity');
        $product = Product::factory()->create();
        $paid = $cart->items()->create($this->line($product, false, 3, 19.5));
        $snapshot = $paid->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_07_24_090000_change_cart_item_line_identity.php');

        $migration->down();
        $this->assertTrue(Schema::hasIndex('cart_items', 'cart_items_cart_id_product_id_unique', 'unique'));
        $this->assertFalse(Schema::hasIndex('cart_items', 'cart_items_cart_product_gift_unique', 'unique'));
        $this->assertMysqlForeignKeysRemainEnforced($cart, $product);

        $migration->up();
        $this->assertTrue(Schema::hasIndex('cart_items', 'cart_items_cart_product_gift_unique', 'unique'));
        $this->assertFalse(Schema::hasIndex('cart_items', 'cart_items_cart_id_product_id_unique', 'unique'));
        $this->assertMysqlForeignKeysRemainEnforced($cart, $product);
        $this->assertEquals($snapshot, $paid->fresh()->getAttributes());
    }

    private function assertMysqlForeignKeysRemainEnforced(Cart $cart, Product $product): void
    {
        $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'cart_items')
            ->whereIn('COLUMN_NAME', ['cart_id', 'product_id'])
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('REFERENCED_TABLE_NAME', 'COLUMN_NAME')
            ->all();

        $this->assertSame('carts', $foreignKeys['cart_id'] ?? null);
        $this->assertSame('products', $foreignKeys['product_id'] ?? null);
        $this->assertForeignKeyRejects([
            'cart_id' => ((int) Cart::query()->max('id')) + 1_000_000,
            'product_id' => $product->id,
        ]);
        $this->assertForeignKeyRejects([
            'cart_id' => $cart->id,
            'product_id' => ((int) Product::query()->max('id')) + 1_000_000,
        ]);
    }

    private function assertForeignKeyRejects(array $references): void
    {
        try {
            DB::table('cart_items')->insert($references + [
                'quantity' => 1,
                'is_gift' => false,
                'unit_price' => 10,
                'total_price' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected the Cart item foreign key constraint to reject an invalid reference.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) ($exception->errorInfo[0] ?? $exception->getCode()));
        }
    }

    private function cart(string $name = 'mutation-policy'): Cart
    {
        return Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
    }

    private function line(Product $product, bool $gift, int $quantity, float $unitPrice): array
    {
        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'is_gift' => $gift,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ];
    }

    private function giftPromotion(Product $product, int $quantity = 1): Promotion
    {
        $promotion = Promotion::query()->create([
            'name' => 'Persistent gift',
            'type' => 'gift_product',
            'status' => 'active',
            'priority' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'stackable' => true,
        ]);
        $promotion->actions()->create([
            'action_type' => 'gift_product',
            'configuration' => ['product_id' => $product->id, 'quantity' => $quantity],
        ]);

        return $promotion;
    }
}
