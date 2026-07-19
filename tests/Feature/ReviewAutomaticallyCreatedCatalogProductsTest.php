<?php

namespace Tests\Feature;

use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReviewAutomaticallyCreatedCatalogProductsTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN_SKUS = [
        'VMA3600-10000S',
        'VMC4460P-100EUS',
        'VMC4260P-100EUS',
    ];

    public function test_dry_run_reports_known_skus_and_does_not_mutate_database(): void
    {
        $products = collect([
            $this->knownProduct(self::KNOWN_SKUS[0], 'Arlo Essential Solar Panel Charger - White'),
            $this->knownProduct(self::KNOWN_SKUS[1], 'Arlo Pro 5 Outdoor Security Camera - 4 Camera Kit - White'),
        ]);
        $productSnapshots = $products->mapWithKeys(fn (Product $product): array => [
            $product->id => $this->fullProductSnapshot($product),
        ]);

        $supplierProduct = $this->supplierProductFor($products->first());
        $productAttributeValue = ProductAttributeValue::factory()->create(['product_id' => $products->first()->id]);
        $categoryProductAttribute = CategoryProductAttribute::factory()->create([
            'category_id' => $products->first()->category_id,
        ]);

        $supplierProductSnapshot = $this->modelSnapshot($supplierProduct);
        $productAttributeValueSnapshot = $this->modelSnapshot($productAttributeValue);
        $categoryProductAttributeSnapshot = $this->modelSnapshot($categoryProductAttribute);

        $this->assertSame(0, Artisan::call('catalog:review-auto-created-products'));
        $output = Artisan::output();

        $this->assertStringContainsString('Dry-run only. No records were changed.', $output);
        $this->assertStringContainsString('Known SKUs considered: '.implode(', ', self::KNOWN_SKUS), $output);
        $this->assertStringContainsString('Target workflow_status: '.Product::WORKFLOW_PENDING_REVIEW, $output);
        $this->assertStringContainsString('Products found: 2', $output);
        $this->assertStringContainsString('Products missing: 1', $output);
        $this->assertStringContainsString('Products to change: 2', $output);
        $this->assertStringContainsString('Products changed: 0', $output);
        $this->assertStringContainsString('SKU '.self::KNOWN_SKUS[2].': missing; no change proposed.', $output);
        $this->assertStringContainsString('current workflow_status=published', $output);
        $this->assertStringContainsString('proposed workflow_status=pending_review', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('product_attribute_values changed: 0', $output);
        $this->assertStringContainsString('category_product_attributes changed: 0', $output);

        foreach ($products as $product) {
            $this->assertSame($productSnapshots[$product->id], $this->fullProductSnapshot($product->fresh()));
        }

        $this->assertEquals($supplierProductSnapshot, $this->modelSnapshot($supplierProduct->fresh()));
        $this->assertEquals($productAttributeValueSnapshot, $this->modelSnapshot($productAttributeValue->fresh()));
        $this->assertEquals($categoryProductAttributeSnapshot, $this->modelSnapshot($categoryProductAttribute->fresh()));
    }

    public function test_apply_moves_only_known_skus_to_review_without_changing_catalog_content_or_staging(): void
    {
        $products = collect([
            $this->knownProduct(self::KNOWN_SKUS[0], 'Arlo Essential Solar Panel Charger - White'),
            $this->knownProduct(self::KNOWN_SKUS[1], 'Arlo Pro 5 Outdoor Security Camera - 4 Camera Kit - White'),
            $this->knownProduct(self::KNOWN_SKUS[2], 'Arlo Pro 5 Outdoor Security Camera - 2 Camera Kit - White'),
        ]);
        $products->first()->forceFill(['review_notes' => 'Existing admin review note.'])->save();
        $unlistedProduct = Product::factory()->supplierPublished()->create([
            'sku' => 'NOT-IN-ALLOWLIST',
            'supplier_sku' => 'NOT-IN-ALLOWLIST',
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'active' => true,
        ]);

        $protectedSnapshots = $products
            ->concat([$unlistedProduct])
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => $this->protectedProductSnapshot($product),
            ]);

        $image = ProductImage::query()->create([
            'product_id' => $products->first()->id,
            'path' => 'products/arlo-camera.jpg',
            'alt_text' => 'Arlo camera',
            'sort_order' => 10,
            'is_primary' => true,
        ]);
        $supplierProduct = $this->supplierProductFor($products->first());
        $attribute = ProductAttribute::factory()->create(['code' => 'battery_life']);
        $productAttributeValue = ProductAttributeValue::factory()->create([
            'product_id' => $products->first()->id,
            'product_attribute_id' => $attribute->id,
            'value_text' => '6 months',
            'custom_value' => '6 months',
        ]);
        $categoryProductAttribute = CategoryProductAttribute::factory()->create([
            'category_id' => $products->first()->category_id,
            'product_attribute_id' => $attribute->id,
            'is_filterable' => true,
            'sort_order' => 40,
        ]);

        $imageSnapshot = $this->modelSnapshot($image);
        $supplierProductSnapshot = $this->modelSnapshot($supplierProduct);
        $productAttributeValueSnapshot = $this->modelSnapshot($productAttributeValue);
        $categoryProductAttributeSnapshot = $this->modelSnapshot($categoryProductAttribute);
        $productCount = Product::query()->count();
        $supplierProductCount = SupplierProduct::query()->count();
        $productAttributeValueCount = ProductAttributeValue::query()->count();
        $categoryProductAttributeCount = CategoryProductAttribute::query()->count();

        $this->assertSame(0, Artisan::call('catalog:review-auto-created-products', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Apply mode. Known automatically-created catalog products were reviewed.', $output);
        $this->assertStringContainsString('Products found: 3', $output);
        $this->assertStringContainsString('Products missing: 0', $output);
        $this->assertStringContainsString('Products to change: 3', $output);
        $this->assertStringContainsString('Products changed: 3', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('product_attribute_values changed: 0', $output);
        $this->assertStringContainsString('category_product_attributes changed: 0', $output);

        foreach ($products as $product) {
            $fresh = $product->fresh();

            $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $fresh->workflow_status);
            $this->assertSame('hidden', $fresh->product_status);
            $this->assertFalse((bool) $fresh->active);
            $this->assertStringContainsString('Phase 9C.4.2 supplier import safety hotfix', (string) $fresh->review_notes);
            $this->assertStringContainsString(
                $product->is($products->first()) ? 'Existing admin review note.' : 'Phase 9C.4.2 supplier import safety hotfix',
                (string) $fresh->review_notes,
            );
            $this->assertSame($protectedSnapshots[$product->id], $this->protectedProductSnapshot($fresh));
        }

        $this->assertSame(Product::WORKFLOW_PUBLISHED, $unlistedProduct->fresh()->workflow_status);
        $this->assertSame('active', $unlistedProduct->fresh()->product_status);
        $this->assertTrue((bool) $unlistedProduct->fresh()->active);
        $this->assertSame($protectedSnapshots[$unlistedProduct->id], $this->protectedProductSnapshot($unlistedProduct->fresh()));

        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame($supplierProductCount, SupplierProduct::query()->count());
        $this->assertSame($productAttributeValueCount, ProductAttributeValue::query()->count());
        $this->assertSame($categoryProductAttributeCount, CategoryProductAttribute::query()->count());
        $this->assertEquals($imageSnapshot, $this->modelSnapshot($image->fresh()));
        $this->assertEquals($supplierProductSnapshot, $this->modelSnapshot($supplierProduct->fresh()));
        $this->assertEquals($productAttributeValueSnapshot, $this->modelSnapshot($productAttributeValue->fresh()));
        $this->assertEquals($categoryProductAttributeSnapshot, $this->modelSnapshot($categoryProductAttribute->fresh()));

        $updatedAtAfterFirstApply = $products->mapWithKeys(fn (Product $product): array => [
            $product->id => (string) $product->fresh()->updated_at,
        ]);

        $this->assertSame(0, Artisan::call('catalog:review-auto-created-products', ['--apply' => true]));
        $secondOutput = Artisan::output();

        $this->assertStringContainsString('Products to change: 0', $secondOutput);
        $this->assertStringContainsString('Products changed: 0', $secondOutput);

        foreach ($products as $product) {
            $this->assertSame($updatedAtAfterFirstApply[$product->id], (string) $product->fresh()->updated_at);
        }
    }

    public function test_apply_accepts_explicit_draft_target_and_rejects_invalid_status(): void
    {
        $product = $this->knownProduct(self::KNOWN_SKUS[0], 'Arlo Essential Solar Panel Charger - White');

        $this->assertSame(0, Artisan::call('catalog:review-auto-created-products', [
            '--apply' => true,
            '--status' => Product::WORKFLOW_DRAFT,
        ]));

        $this->assertSame(Product::WORKFLOW_DRAFT, $product->fresh()->workflow_status);
        $this->assertSame('draft', $product->fresh()->product_status);
        $this->assertFalse((bool) $product->fresh()->active);

        $this->assertSame(1, Artisan::call('catalog:review-auto-created-products', [
            '--status' => Product::WORKFLOW_PUBLISHED,
        ]));
        $this->assertStringContainsString('Invalid target status "published".', Artisan::output());
    }

    private function knownProduct(string $sku, string $name): Product
    {
        return Product::factory()->supplierPublished()->create([
            'sku' => $sku,
            'supplier_sku' => $sku,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'short_description' => 'Supplier-created short description',
            'description' => '<p>Supplier-created description</p>',
            'meta_title' => $name.' SEO',
            'meta_description' => $name.' SEO description',
            'price' => 129.99,
            'regular_price' => 129.99,
            'final_selling_price' => 129.99,
            'supplier_price_raw' => 99.99,
            'quantity' => 7,
            'stock_status' => Product::STOCK_STATUS_IN_STOCK,
            'created_at' => '2026-07-04 04:04:09',
            'updated_at' => '2026-07-04 04:04:09',
        ]);
    }

    private function supplierProductFor(Product $product): SupplierProduct
    {
        $supplier = $product->supplier ?: Supplier::factory()->create();

        return SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'supplier_sku' => $product->supplier_sku,
            'ean' => $product->ean,
            'mpn' => $product->mpn,
            'name' => $product->name,
            'brand_name' => $product->brand?->name,
            'category_name' => $product->category?->name,
            'price' => $product->price,
            'supplier_price_raw' => $product->supplier_price_raw,
            'quantity' => $product->quantity,
            'currency' => Product::CATALOG_CURRENCY,
            'raw_data' => ['source' => 'test'],
            'payload_hash' => 'review-auto-created-'.$product->sku,
            'received_at' => now(),
            'status' => 'new',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function protectedProductSnapshot(Product $product): array
    {
        return $this->rawProductValues($product, [
            'category_id',
            'brand_id',
            'supplier_id',
            'sku',
            'supplier_sku',
            'ean',
            'mpn',
            'name',
            'name_translations',
            'lock_name',
            'slug',
            'slug_translations',
            'short_description',
            'short_description_translations',
            'description',
            'description_translations',
            'lock_descriptions',
            'purchase_price',
            'supplier_price_raw',
            'recommended_price',
            'final_selling_price',
            'regular_price',
            'source',
            'apply_pricing_rules',
            'price_source',
            'price',
            'promo_price',
            'sale_price',
            'sale_price_source',
            'quantity',
            'reserved_quantity',
            'stock_status',
            'availability_status_id',
            'external_availability_status',
            'external_availability_label',
            'availability_message',
            'supplier_lead_time_days',
            'manual_override',
            'warranty_months',
            'featured',
            'new_product',
            'bestseller',
            'meta_title',
            'meta_title_translations',
            'meta_description',
            'meta_description_translations',
            'meta_keywords',
            'lock_seo',
            'searchable_keywords',
            'specifications',
            'source_payload',
            'published_at',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fullProductSnapshot(Product $product): array
    {
        return $this->rawProductValues($product, [
            ...array_keys($this->protectedProductSnapshot($product)),
            'workflow_status',
            'product_status',
            'active',
            'review_notes',
            'updated_at',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function modelSnapshot(object $model): array
    {
        $attributes = $model->fresh()->getAttributes();
        ksort($attributes);

        return $attributes;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function rawProductValues(Product $product, array $keys): array
    {
        $product = $product->fresh();

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $product->getRawOriginal($key)])
            ->all();
    }
}
