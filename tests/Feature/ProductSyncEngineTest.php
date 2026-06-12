<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Models\ProductSyncLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSyncEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_product_sync_updates_existing_product_by_mpn(): void
    {
        $this->seed();

        $supplierProduct = SupplierProduct::query()
            ->where('supplier_sku', 'SUP-MC-LAP-001')
            ->firstOrFail();

        app(ProductSyncService::class)->sync($supplierProduct);

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->assertSame('1393.18', $product->price);
        $this->assertSame(12, $product->quantity);
        $this->assertSame($supplierProduct->supplier_id, $product->supplier_id);
        $this->assertSame('synced', $supplierProduct->refresh()->status);
        $this->assertDatabaseHas('product_sync_logs', [
            'product_id' => $product->id,
            'supplier_product_id' => $supplierProduct->id,
            'status' => 'synced',
        ]);
        $this->assertSame(1, ProductSupplierOffer::query()->where('product_id', $product->id)->count());
    }

    public function test_supplier_product_sync_creates_product_when_no_match_exists(): void
    {
        $this->seed();

        $supplier = Supplier::query()->firstOrFail();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-NEW-001',
            'ean' => '9999999999999',
            'mpn' => 'NEW-MPN-001',
            'name' => 'New Supplier Keyboard',
            'brand_name' => 'Logitech',
            'category_name' => 'Keyboards',
            'price' => 49.99,
            'quantity' => 5,
            'currency' => 'BGN',
            'raw_data' => ['sku' => 'SUP-NEW-001'],
            'payload_hash' => sha1('SUP-NEW-001'),
            'received_at' => now(),
            'status' => 'new',
        ]);

        app(ProductSyncService::class)->sync($supplierProduct);

        $this->assertDatabaseHas('products', [
            'supplier_sku' => 'SUP-NEW-001',
            'ean' => '9999999999999',
            'mpn' => 'NEW-MPN-001',
            'active' => false,
        ]);

        $this->assertSame('synced', $supplierProduct->refresh()->status);
        $this->assertSame('created', ProductSyncLog::query()->where('supplier_product_id', $supplierProduct->id)->firstOrFail()->action);
    }
}
