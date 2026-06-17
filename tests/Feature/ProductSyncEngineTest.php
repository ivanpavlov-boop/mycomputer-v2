<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductDiscountRule;
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

    public function test_manual_product_is_not_changed_during_automatic_supplier_sync(): void
    {
        $supplier = Supplier::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Test Brand', 'slug' => 'test-brand']);
        $product = Product::factory()->create([
            'supplier_id' => null,
            'supplier_sku' => null,
            'brand_id' => $brand->id,
            'sku' => 'MANUAL-GPU-001',
            'mpn' => 'MANUAL-MPN-001',
            'source' => Product::SOURCE_MANUAL,
            'apply_pricing_rules' => false,
            'purchase_price' => 700,
            'supplier_price_raw' => null,
            'recommended_price' => null,
            'final_selling_price' => 999,
            'regular_price' => 999,
            'price' => 999,
            'sale_price' => 899,
            'sale_price_starts_at' => now()->subDay(),
            'sale_price_ends_at' => now()->addDay(),
            'promo_price' => 899,
            'promo_start' => now()->subDay(),
            'promo_end' => now()->addDay(),
            'sale_price_source' => Product::SALE_PRICE_SOURCE_MANUAL,
            'quantity' => 3,
            'external_availability_status' => 'manual-status',
            'external_availability_label' => 'Manual Status',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'SUP-MANUAL-GPU-001',
            'mpn' => 'MANUAL-MPN-001',
            'price' => 100,
            'quantity' => 8,
            'raw_data' => ['image' => 'https://example.test/manual-sync-image.jpg'],
        ]);

        PricingRule::query()->create([
            'name' => 'High global margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 50,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        $log = app(ProductSyncService::class)->sync($supplierProduct);

        $product->refresh();

        $this->assertSame('skipped', $log->status);
        $this->assertSame('skipped', $supplierProduct->refresh()->status);
        $this->assertSame(Product::SOURCE_MANUAL, $product->source);
        $this->assertFalse($product->apply_pricing_rules);
        $this->assertNull($product->supplier_id);
        $this->assertNull($product->supplier_sku);
        $this->assertSame('700.00', $product->purchase_price);
        $this->assertNull($product->supplier_price_raw);
        $this->assertNull($product->recommended_price);
        $this->assertSame('999.00', $product->final_selling_price);
        $this->assertSame('999.00', $product->regular_price);
        $this->assertSame('999.00', $product->price);
        $this->assertSame('899.00', $product->sale_price);
        $this->assertSame(899.0, $product->activeSalePrice());
        $this->assertSame('899.00', $product->promo_price);
        $this->assertSame(Product::SALE_PRICE_SOURCE_MANUAL, $product->sale_price_source);
        $this->assertSame(3, $product->quantity);
        $this->assertSame('manual-status', $product->external_availability_status);
        $this->assertSame('Manual Status', $product->external_availability_label);
        $this->assertSame(0, $product->images()->count());
        $this->assertSame(0, ProductSupplierOffer::query()->where('product_id', $product->id)->count());
    }

    public function test_manual_product_can_be_explicitly_repriced_by_admin_opt_in(): void
    {
        $supplier = Supplier::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Test Brand', 'slug' => 'test-brand']);
        $product = Product::factory()->create([
            'supplier_id' => null,
            'brand_id' => $brand->id,
            'sku' => 'MANUAL-GPU-002',
            'mpn' => 'MANUAL-MPN-002',
            'source' => Product::SOURCE_MANUAL,
            'apply_pricing_rules' => true,
            'purchase_price' => 700,
            'final_selling_price' => 999,
            'regular_price' => 999,
            'price' => 999,
            'sale_price' => 129,
            'sale_price_starts_at' => now()->subDay(),
            'sale_price_ends_at' => now()->addDay(),
            'promo_price' => 129,
            'promo_start' => now()->subDay(),
            'promo_end' => now()->addDay(),
            'sale_price_source' => Product::SALE_PRICE_SOURCE_MANUAL,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'product_id' => $product->id,
            'supplier_sku' => 'SUP-MANUAL-GPU-002',
            'mpn' => 'MANUAL-MPN-002',
            'price' => 100,
            'quantity' => 5,
        ]);

        PricingRule::query()->create([
            'name' => 'Explicit manual pricing',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 50,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        app(ProductSyncService::class)->sync($supplierProduct);

        $product->refresh();

        $this->assertSame(Product::SOURCE_MANUAL, $product->source);
        $this->assertTrue($product->apply_pricing_rules);
        $this->assertSame('100.00', $product->purchase_price);
        $this->assertSame('100.00', $product->supplier_price_raw);
        $this->assertSame('150.00', $product->final_selling_price);
        $this->assertSame('150.00', $product->regular_price);
        $this->assertSame('150.00', $product->price);
        $this->assertSame('129.00', $product->sale_price);
        $this->assertSame(129.0, $product->activeSalePrice());
        $this->assertSame('129.00', $product->promo_price);
        $this->assertSame(Product::SALE_PRICE_SOURCE_MANUAL, $product->sale_price_source);
    }

    public function test_cross_supplier_winning_offer_controls_synced_supplier_price_stock_and_availability_metadata(): void
    {
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM', 'priority' => 20]);
        $asbis = Supplier::factory()->create(['company_name' => 'ASBIS', 'priority' => 10]);
        $brand = Brand::factory()->create(['name' => 'Test Brand', 'slug' => 'test-brand']);
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'ean' => '4564564564564',
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'price' => 999,
            'quantity' => 1,
            'supplier_id' => $apcom->id,
            'supplier_sku' => 'APCOM-OLD',
        ]);
        $asbisProduct = $this->supplierProduct($asbis, [
            'supplier_sku' => 'ASBIS-WIN',
            'ean' => '4564564564564',
            'price' => 80,
            'quantity' => 9,
            'external_availability_status' => 'asbis-available',
            'external_availability_label' => 'ASBIS Available',
        ]);
        $apcomProduct = $this->supplierProduct($apcom, [
            'supplier_sku' => 'APCOM-LOSE',
            'ean' => '4564564564564',
            'price' => 100,
            'quantity' => 4,
            'external_availability_status' => 'apcom-available',
            'external_availability_label' => 'APCOM Available',
        ]);
        ProductSupplierOffer::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $asbisProduct->supplier_id,
            'supplier_product_id' => $asbisProduct->id,
            'supplier_sku' => $asbisProduct->supplier_sku,
            'price' => $asbisProduct->price,
            'quantity' => $asbisProduct->quantity,
            'currency' => 'EUR',
            'supplier_priority' => $asbis->priority,
            'is_preferred' => false,
            'last_seen_at' => now(),
        ]);
        PricingRule::query()->create([
            'name' => 'Global margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        app(ProductSyncService::class)->sync($apcomProduct);

        $product->refresh();

        $this->assertSame($asbis->id, $product->supplier_id);
        $this->assertSame('ASBIS-WIN', $product->supplier_sku);
        $this->assertSame(9, $product->quantity);
        $this->assertSame('80.00', $product->purchase_price);
        $this->assertSame('80.00', $product->supplier_price_raw);
        $this->assertSame('96.00', $product->price);
        $this->assertSame('asbis-available', $product->external_availability_status);
        $this->assertSame('ASBIS Available', $product->external_availability_label);
        $this->assertSame($asbisProduct->id, $product->source_payload['selected_supplier_product_id']);
    }

    public function test_supplier_imported_product_gets_discount_rule_sale_price(): void
    {
        $supplier = Supplier::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Test Brand', 'slug' => 'test-brand']);
        $product = Product::factory()->create([
            'supplier_id' => $supplier->id,
            'brand_id' => $brand->id,
            'sku' => 'SUPPLIER-GPU-001',
            'mpn' => 'SUPPLIER-MPN-001',
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'price' => 999,
            'promo_price' => null,
            'sale_price_source' => null,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'SUP-SUPPLIER-GPU-001',
            'mpn' => 'SUPPLIER-MPN-001',
            'price' => 100,
            'quantity' => 5,
        ]);

        PricingRule::query()->create([
            'name' => 'Supplier margin',
            'scope_type' => PricingRule::SCOPE_SUPPLIER,
            'supplier_id' => $supplier->id,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 50,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);
        ProductDiscountRule::query()->create([
            'name' => 'Supplier campaign',
            'scope_type' => ProductDiscountRule::SCOPE_SUPPLIER,
            'supplier_id' => $supplier->id,
            'discount_type' => ProductDiscountRule::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
        ]);

        app(ProductSyncService::class)->sync($supplierProduct);

        $product->refresh();

        $this->assertSame('150.00', $product->regular_price);
        $this->assertSame('150.00', $product->price);
        $this->assertSame('135.00', $product->sale_price);
        $this->assertSame(135.0, $product->activeSalePrice());
        $this->assertSame('135.00', $product->promo_price);
        $this->assertSame(Product::SALE_PRICE_SOURCE_PROMOTION_RULE, $product->sale_price_source);
    }

    public function test_supplier_imported_product_does_not_get_sale_price_without_discount_rule(): void
    {
        $supplier = Supplier::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Test Brand', 'slug' => 'test-brand']);
        $product = Product::factory()->create([
            'supplier_id' => $supplier->id,
            'brand_id' => $brand->id,
            'sku' => 'SUPPLIER-GPU-002',
            'mpn' => 'SUPPLIER-MPN-002',
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'price' => 999,
            'promo_price' => 899,
            'sale_price_source' => Product::SALE_PRICE_SOURCE_PROMOTION_RULE,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'supplier_sku' => 'SUP-SUPPLIER-GPU-002',
            'mpn' => 'SUPPLIER-MPN-002',
            'price' => 100,
            'quantity' => 5,
        ]);

        PricingRule::query()->create([
            'name' => 'Supplier margin',
            'scope_type' => PricingRule::SCOPE_SUPPLIER,
            'supplier_id' => $supplier->id,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 50,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);

        app(ProductSyncService::class)->sync($supplierProduct);

        $product->refresh();

        $this->assertSame('150.00', $product->regular_price);
        $this->assertSame('150.00', $product->price);
        $this->assertNull($product->sale_price);
        $this->assertNull($product->promo_price);
        $this->assertNull($product->sale_price_source);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-'.fake()->unique()->bothify('####??'),
            'ean' => fake()->ean13(),
            'mpn' => fake()->unique()->bothify('MPN-####??'),
            'name' => 'Supplier Sync Test Product',
            'brand_name' => 'Test Brand',
            'category_name' => 'Test Category',
            'price' => 100,
            'quantity' => 5,
            'currency' => 'EUR',
            'raw_data' => ['source' => 'product-sync-test'],
            'payload_hash' => fake()->unique()->sha1(),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }
}
