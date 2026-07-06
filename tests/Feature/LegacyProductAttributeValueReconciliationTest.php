<?php

namespace Tests\Feature;

use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Products\ProductSpecificationQualityResult;
use App\Services\Products\ProductSpecificationQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyProductAttributeValueReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_detects_legacy_storage_values_without_mutating_database(): void
    {
        [$product] = $this->productWithAssignedTargets([
            'storage_capacity' => ProductAttribute::TYPE_SELECT,
            'storage_type' => ProductAttribute::TYPE_SELECT,
        ]);
        $this->option($this->attributeByCode('storage_capacity'), '512 GB');
        $this->option($this->attributeByCode('storage_type'), 'SSD');
        $this->legacyValue($product, 'Storage', '512 GB SSD');
        $before = $this->counts();
        $productSnapshot = $product->fresh()->only(['name', 'sku', 'category_id', 'workflow_status', 'product_status', 'active', 'updated_at']);

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', ['--sku' => $product->sku]));
        $output = Artisan::output();

        $this->assertStringContainsString('Dry-run only. No records were changed.', $output);
        $this->assertStringContainsString('Legacy values found: 1', $output);
        $this->assertStringContainsString('product_attribute_values to create: 2', $output);
        $this->assertStringContainsString('target storage_capacity', $output);
        $this->assertStringContainsString('target storage_type', $output);
        $this->assertStringContainsString('product_attribute_values changed: 0', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertSame($before, $this->counts());
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
    }

    public function test_apply_requires_explicit_sku_or_product_id(): void
    {
        $this->assertSame(1, Artisan::call('product-attributes:reconcile-legacy-values', ['--apply' => true]));
        $this->assertStringContainsString('Refusing unrestricted apply', Artisan::output());
    }

    public function test_apply_creates_safe_storage_targets_and_preserves_legacy_value(): void
    {
        [$product] = $this->productWithAssignedTargets([
            'storage_capacity' => ProductAttribute::TYPE_SELECT,
            'storage_type' => ProductAttribute::TYPE_SELECT,
        ]);
        $this->option($this->attributeByCode('storage_capacity'), '1 TB');
        $this->option($this->attributeByCode('storage_type'), 'NVMe');
        $legacy = $this->legacyValue($product, 'Storage', '1 TB NVMe');
        $beforeCounts = $this->counts();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'supplier_sku' => 'LEGACY-ATTR-STAGED-001',
            'name' => 'Staged value',
            'currency' => 'EUR',
            'raw_data' => ['storage' => '1 TB NVMe'],
            'payload_hash' => 'legacy-attr-staged-001',
            'received_at' => now(),
            'status' => 'new',
        ]);
        $supplierSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', [
            '--apply' => true,
            '--sku' => $product->sku,
        ]));
        $output = Artisan::output();

        $this->assertStringContainsString('Created rows: 2', $output);
        $this->assertStringContainsString('legacy values deleted: 0', $output);
        $this->assertStringContainsString('legacy values changed: 0', $output);
        $this->assertDatabaseHas('product_attribute_values', [
            'id' => $legacy->id,
            'product_id' => $product->id,
            'custom_value' => '1 TB NVMe',
        ]);
        $this->assertTargetSelectValue($product, 'storage_capacity', '1 TB');
        $this->assertTargetSelectValue($product, 'storage_type', 'NVMe');
        $this->assertSame($beforeCounts['product_attributes'], ProductAttribute::query()->count());
        $this->assertSame($beforeCounts['attribute_values'], AttributeValue::query()->count());
        $this->assertSame($beforeCounts['category_product_attributes'], CategoryProductAttribute::query()->count());
        $this->assertSame($beforeCounts['supplier_products'] + 1, SupplierProduct::query()->count());
        $this->assertEquals($supplierSnapshot, $supplierProduct->fresh()->only(array_keys($supplierSnapshot)));
    }

    public function test_apply_is_idempotent_and_does_not_duplicate_targets(): void
    {
        [$product] = $this->productWithAssignedTargets(['screen_size' => ProductAttribute::TYPE_SELECT]);
        $this->option($this->attributeByCode('screen_size'), '15.6"');
        $this->legacyValue($product, 'Display', '15.6 inch IPS');

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', ['--apply' => true, '--product-id' => $product->id]));
        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', ['--apply' => true, '--product-id' => $product->id]));
        $output = Artisan::output();

        $this->assertSame(1, ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('product_attribute_id', $this->attributeByCode('screen_size')->id)
            ->count());
        $this->assertStringContainsString('Created rows: 0', $output);
        $this->assertStringContainsString('target_already_filled', $output);
    }

    public function test_display_ram_processor_and_refresh_rate_slug_mismatch_are_reconciled(): void
    {
        [$product] = $this->productWithAssignedTargets([
            'screen_size' => ProductAttribute::TYPE_SELECT,
            'ram' => ProductAttribute::TYPE_SELECT,
            'processor' => ProductAttribute::TYPE_TEXT,
            'refresh-rate' => ProductAttribute::TYPE_SELECT,
        ]);
        $this->option($this->attributeByCode('screen_size'), '16"');
        $this->option($this->attributeByCode('ram'), '32 GB');
        $this->option($this->attributeBySlug('refresh-rate'), '144 Hz');
        $this->legacyValue($product, 'Display', '16 inch');
        $this->legacyValue($product, 'Memory', '32GB DDR5');
        $this->legacyValue($product, 'CPU', 'Intel Core i7');
        $this->legacyValue($product, 'Refresh Rate Legacy', '144Hz');

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', ['--apply' => true, '--sku' => $product->sku]));

        $this->assertTargetSelectValue($product, 'screen_size', '16"');
        $this->assertTargetSelectValue($product, 'ram', '32 GB');
        $this->assertTargetTextValue($product, 'processor', 'Intel Core i7');
        $this->assertTargetSelectValue($product, 'refresh-rate', '144 Hz', true);
    }

    public function test_ambiguous_missing_target_and_missing_option_are_reported_without_writes(): void
    {
        [$ambiguousProduct] = $this->productWithAssignedTargets(['storage_capacity' => ProductAttribute::TYPE_SELECT]);
        $this->option($this->attributeByCode('storage_capacity'), '512 GB');
        $this->legacyValue($ambiguousProduct, 'Storage', '512 GB SSD + 1 TB HDD');
        $ambiguousTargetId = $this->attributeByCode('storage_capacity')->id;

        [$missingTargetProduct] = $this->productWithAssignedTargets([]);
        $this->legacyValue($missingTargetProduct, 'GPU', 'NVIDIA RTX 4060');

        [$missingOptionProduct] = $this->productWithAssignedTargets(['screen_size' => ProductAttribute::TYPE_SELECT]);
        $this->legacyValue($missingOptionProduct, 'Display', '17.3 inch');
        $missingOptionTargetId = $this->attributeByCode('screen_size')->id;

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values'));
        $output = Artisan::output();

        $this->assertStringContainsString('action=skipped_ambiguous', $output);
        $this->assertStringContainsString('action=missing_target_attribute', $output);
        $this->assertStringContainsString('action=missing_target_option', $output);
        $this->assertStringContainsString('product_attribute_values changed: 0', $output);
        $this->assertSame(0, ProductAttributeValue::query()
            ->where(function ($query) use ($ambiguousProduct, $ambiguousTargetId, $missingOptionProduct, $missingOptionTargetId): void {
                $query
                    ->where(fn ($subQuery) => $subQuery
                        ->where('product_id', $ambiguousProduct->id)
                        ->where('product_attribute_id', $ambiguousTargetId))
                    ->orWhere(fn ($subQuery) => $subQuery
                        ->where('product_id', $missingOptionProduct->id)
                        ->where('product_attribute_id', $missingOptionTargetId));
            })
            ->count());
    }

    public function test_target_already_filled_is_skipped_without_overwrite(): void
    {
        [$product] = $this->productWithAssignedTargets(['ram' => ProductAttribute::TYPE_SELECT]);
        $target = $this->attributeByCode('ram');
        $existingOption = $this->option($target, '16 GB');
        $newOption = $this->option($target, '32 GB');
        $this->legacyValue($product, 'Memory', '32 GB DDR5');
        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $target->id,
            'attribute_value_id' => $existingOption->id,
            'custom_value' => '16 GB',
            'value_text' => '16 GB',
        ]);

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', ['--apply' => true, '--sku' => $product->sku]));

        $this->assertStringContainsString('target_already_filled', Artisan::output());
        $this->assertSame(1, ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('product_attribute_id', $target->id)
            ->count());
        $this->assertDatabaseMissing('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $target->id,
            'attribute_value_id' => $newOption->id,
        ]);
    }

    public function test_quality_score_improves_after_storage_and_display_reconciliation(): void
    {
        [$product] = $this->productWithAssignedTargets([
            'storage_capacity' => ProductAttribute::TYPE_SELECT,
            'storage_type' => ProductAttribute::TYPE_SELECT,
            'screen_size' => ProductAttribute::TYPE_SELECT,
        ], required: true);
        $this->option($this->attributeByCode('storage_capacity'), '512 GB');
        $this->option($this->attributeByCode('storage_type'), 'SSD');
        $this->option($this->attributeByCode('screen_size'), '15.6"');
        $this->legacyValue($product, 'Storage', '512 GB SSD');
        $this->legacyValue($product, 'Display', '15.6 inch');
        $service = app(ProductSpecificationQualityService::class);

        $before = $service->evaluate($product->fresh());
        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, $before->status);
        $this->assertSame(0, $before->filledCount);

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', ['--apply' => true, '--sku' => $product->sku]));

        $after = $service->evaluate($product->fresh());
        $this->assertSame(ProductSpecificationQualityResult::STATUS_GOOD, $after->status);
        $this->assertSame(3, $after->filledCount);
    }

    public function test_filters_limit_scanning_and_command_is_listed(): void
    {
        [$matchingProduct] = $this->productWithAssignedTargets(['ram' => ProductAttribute::TYPE_SELECT], sku: 'LEGACY-FILTER-001');
        $this->option($this->attributeByCode('ram'), '16 GB');
        $this->legacyValue($matchingProduct, 'Memory', '16 GB');

        [$otherProduct] = $this->productWithAssignedTargets(['processor' => ProductAttribute::TYPE_TEXT], sku: 'LEGACY-FILTER-002');
        $this->legacyValue($otherProduct, 'CPU', 'Intel Core i5');

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', [
            '--limit' => 1,
            '--attribute' => 'Memory',
        ]));
        $output = Artisan::output();

        $this->assertStringContainsString('Products scanned: 1', $output);
        $this->assertStringContainsString('source', $output);
        $this->assertArrayHasKey('product-attributes:reconcile-legacy-values', Artisan::all());
    }

    public function test_quality_remains_warning_only_and_editing_product_does_not_create_rows(): void
    {
        [$product] = $this->productWithAssignedTargets(['ram' => ProductAttribute::TYPE_SELECT], required: true);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, app(ProductSpecificationQualityService::class)->evaluate($product)->status);
        $product->update(['name' => 'Saved without blocking quality']);

        $this->assertSame('Saved without blocking quality', $product->fresh()->name);
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    /**
     * @param  array<string, string>  $targetTypes
     * @return array{0: Product, 1: Category}
     */
    private function productWithAssignedTargets(array $targetTypes, bool $required = false, ?string $sku = null): array
    {
        $category = Category::factory()->create(['slug' => 'legacy-test-'.uniqid(), 'is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => $sku ?? 'LEGACY-'.strtoupper(uniqid()),
        ]);

        foreach ($targetTypes as $code => $type) {
            $attribute = ProductAttribute::factory()->create([
                'code' => str_replace('-', '_', $code),
                'slug' => str_replace('_', '-', $code),
                'name' => str_replace('_', ' ', $code),
                'name_bg' => str_replace('_', ' ', $code),
                'type' => $type,
                'is_active' => true,
            ]);

            if ($code === 'refresh-rate') {
                DB::table('product_attributes')
                    ->where('id', $attribute->id)
                    ->update(['code' => 'legacy_refresh_rate', 'slug' => 'refresh-rate']);
                $attribute->refresh();
            }

            CategoryProductAttribute::factory()->create([
                'category_id' => $category->id,
                'product_attribute_id' => $attribute->id,
                'is_required' => $required,
                'is_filterable' => true,
                'is_visible_on_product' => true,
            ]);
        }

        return [$product, $category];
    }

    private function legacyValue(Product $product, string $attributeName, string $value): ProductAttributeValue
    {
        $attribute = ProductAttribute::factory()->create([
            'code' => $attributeName,
            'slug' => str($attributeName)->slug()->toString(),
            'name' => $attributeName,
            'name_bg' => $attributeName,
            'type' => ProductAttribute::TYPE_TEXT,
            'is_active' => true,
        ]);

        return ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'custom_value' => $value,
            'value_text' => $value,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
        ]);
    }

    private function option(ProductAttribute $attribute, string $value): AttributeValue
    {
        return AttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'value' => $value,
            'slug' => str($value)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function attributeByCode(string $code): ProductAttribute
    {
        return ProductAttribute::query()->where('code', str($code)->slug('_')->toString())->firstOrFail();
    }

    private function attributeBySlug(string $slug): ProductAttribute
    {
        return ProductAttribute::query()->where('slug', $slug)->firstOrFail();
    }

    private function assertTargetSelectValue(Product $product, string $codeOrSlug, string $value, bool $slug = false): void
    {
        $attribute = $slug ? $this->attributeBySlug($codeOrSlug) : $this->attributeByCode($codeOrSlug);
        $option = AttributeValue::query()
            ->where('product_attribute_id', $attribute->id)
            ->where('value', $value)
            ->firstOrFail();

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => $option->id,
            'custom_value' => $value,
            'value_text' => $value,
        ]);
    }

    private function assertTargetTextValue(Product $product, string $code, string $value): void
    {
        $attribute = $this->attributeByCode($code);

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'custom_value' => $value,
            'value_text' => $value,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'products' => Product::query()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
        ];
    }
}
