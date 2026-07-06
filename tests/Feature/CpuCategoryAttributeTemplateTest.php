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
use Tests\TestCase;

class CpuCategoryAttributeTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_cpu_template_dry_run_reports_planned_changes_without_mutating_database(): void
    {
        $category = Category::factory()->create([
            'slug' => 'procesori',
            'description' => 'Existing CPU category description',
            'image_path' => 'categories/cpu.jpg',
            'meta_title' => 'CPU SEO',
            'meta_description' => 'CPU SEO description',
        ]);
        $product = Product::factory()->supplierPublished()->create([
            'category_id' => $category->id,
            'sku' => 'MC-CPU-001',
        ]);
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'supplier_sku' => 'CPU-STAGED-001',
            'name' => 'Staged CPU',
            'currency' => 'EUR',
            'raw_data' => ['Socket' => 'AM5'],
            'payload_hash' => 'cpu-template-staged-001',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $counts = $this->counts();
        $categorySnapshot = $this->categorySnapshot($category);
        $productSnapshot = $product->fresh()->only(['category_id', 'name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']);
        $supplierProductSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template'));
        $output = Artisan::output();

        $this->assertStringContainsString('Dry-run only. No records were changed.', $output);
        $this->assertStringContainsString('Product attributes to create: 12', $output);
        $this->assertStringContainsString('Attribute values to create: 6', $output);
        $this->assertStringContainsString('CPU categories found: 1', $output);
        $this->assertStringContainsString('Category assignments to create: 12', $output);
        $this->assertStringContainsString('Products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('product_attribute_values created: 0', $output);

        $this->assertSame($counts, $this->counts());
        $this->assertEquals($categorySnapshot, $this->categorySnapshot($category->fresh()));
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
        $this->assertEquals($supplierProductSnapshot, $supplierProduct->fresh()->only(array_keys($supplierProductSnapshot)));
    }

    public function test_cpu_template_dry_run_skips_missing_cpu_category_safely(): void
    {
        $counts = $this->counts();

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template'));
        $output = Artisan::output();

        $this->assertStringContainsString('Dry-run only. No records were changed.', $output);
        $this->assertStringContainsString('CPU categories found: 0', $output);
        $this->assertStringContainsString('CPU categories skipped: 1', $output);
        $this->assertStringContainsString('Category assignments to create: 0', $output);
        $this->assertStringContainsString('Skipped CPU category template', $output);
        $this->assertSame($counts, $this->counts());
    }

    public function test_cpu_template_apply_creates_attributes_options_and_assignments_idempotently(): void
    {
        $category = Category::factory()->create(['slug' => 'procesori']);
        $product = Product::factory()->supplierPublished()->create(['category_id' => $category->id]);

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('CPU category attribute template applied.', $output);
        $this->assertStringContainsString('Product attributes created: 12', $output);
        $this->assertStringContainsString('Attribute values created: 6', $output);
        $this->assertStringContainsString('Category assignments created: 12', $output);

        $this->assertSame(12, ProductAttribute::query()->count());
        $this->assertSame(6, AttributeValue::query()->count());
        $this->assertSame(12, CategoryProductAttribute::query()->where('category_id', $category->id)->count());
        $this->assertSame(0, ProductAttributeValue::query()->count());

        $socket = ProductAttribute::query()->where('code', 'cpu_socket')->firstOrFail();
        $this->assertSame(ProductAttribute::TYPE_SELECT, $socket->type);
        $this->assertSame('Сокет', $socket->name_bg);
        $this->assertDatabaseHas('attribute_values', [
            'product_attribute_id' => $socket->id,
            'value' => 'AM5',
            'slug' => 'am5',
        ]);
        $this->assertDatabaseHas('category_product_attributes', [
            'category_id' => $category->id,
            'product_attribute_id' => $socket->id,
            'is_required' => false,
            'is_filterable' => true,
            'is_visible_on_product' => true,
            'is_comparable' => true,
            'sort_order' => 20,
        ]);

        $counts = $this->counts();
        $productSnapshot = $product->fresh()->only(['category_id', 'name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']);

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template', ['--apply' => true]));
        $secondOutput = Artisan::output();

        $this->assertStringContainsString('Product attributes created: 0', $secondOutput);
        $this->assertStringContainsString('Attribute values created: 0', $secondOutput);
        $this->assertStringContainsString('Category assignments created: 0', $secondOutput);
        $this->assertStringContainsString('Category assignments already present: 12', $secondOutput);
        $this->assertSame($counts, $this->counts());
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
    }

    public function test_cpu_template_preserves_existing_admin_edited_attribute_labels(): void
    {
        $category = Category::factory()->create(['slug' => 'cpu']);
        $socket = ProductAttribute::factory()->create([
            'code' => 'cpu_socket',
            'slug' => 'cpu-socket',
            'name' => 'Admin Socket Label',
            'name_bg' => 'Admin Socket Label',
            'name_en' => 'Admin Socket Label',
            'type' => ProductAttribute::TYPE_SELECT,
            'unit' => null,
            'is_filterable' => false,
            'is_visible_on_product' => false,
            'is_comparable' => false,
        ]);

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template', ['--apply' => true]));

        $socket->refresh();
        $this->assertSame('Admin Socket Label', $socket->name_bg);
        $this->assertFalse((bool) $socket->is_filterable);
        $this->assertFalse((bool) $socket->is_visible_on_product);
        $this->assertFalse((bool) $socket->is_comparable);
        $this->assertSame(4, AttributeValue::query()->where('product_attribute_id', $socket->id)->count());
        $this->assertDatabaseHas('category_product_attributes', [
            'category_id' => $category->id,
            'product_attribute_id' => $socket->id,
            'is_filterable' => false,
            'is_visible_on_product' => false,
            'is_comparable' => false,
        ]);
    }

    public function test_cpu_template_makes_quality_expected_attributes_available_without_autofill(): void
    {
        $category = Category::factory()->create(['slug' => 'processors']);
        $product = Product::factory()->supplierPublished()->create(['category_id' => $category->id]);

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template', ['--apply' => true]));

        $result = app(ProductSpecificationQualityService::class)->evaluate($product->fresh());

        $this->assertSame(ProductSpecificationQualityResult::STATUS_NEEDS_DATA, $result->status);
        $this->assertSame(12, $result->expectedCount);
        $this->assertSame(0, $result->filledCount);
        $this->assertSame(12, $result->missingCount);
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_cpu_legacy_values_can_be_proposed_after_template_apply_without_reconciliation_apply(): void
    {
        $category = Category::factory()->create(['slug' => 'procesori']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'MC-CPU-001',
            'name' => 'AMD Ryzen 7 9700X',
        ]);

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template', ['--apply' => true]));

        $this->legacyValue($product, 'Processor', 'AMD Ryzen 7 9700X', 'legacy_processor');
        $this->legacyValue($product, 'Cores', '8');
        $this->legacyValue($product, 'Socket', 'AM5');
        $this->legacyValue($product, 'Base clock', '3.8 GHz');
        $counts = $this->counts();

        $this->assertSame(0, Artisan::call('product-attributes:reconcile-legacy-values', ['--sku' => 'MC-CPU-001']));
        $output = Artisan::output();

        $this->assertStringContainsString('Dry-run only. No records were changed.', $output);
        $this->assertStringContainsString('product_attribute_values to create: 4', $output);
        $this->assertStringContainsString('target processor', $output);
        $this->assertStringContainsString('target cpu_cores', $output);
        $this->assertStringContainsString('target cpu_socket', $output);
        $this->assertStringContainsString('target cpu_base_clock', $output);
        $this->assertStringContainsString('product_attribute_values changed: 0', $output);
        $this->assertSame($counts, $this->counts());
    }

    public function test_cpu_template_does_not_expand_catalog_sync_or_public_filter_features(): void
    {
        Category::factory()->create(['slug' => 'procesori']);

        $this->assertSame(0, Artisan::call('product-attributes:seed-cpu-template', ['--apply' => true]));

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->assertArrayHasKey('product-attributes:seed-cpu-template', Artisan::all());
    }

    private function legacyValue(Product $product, string $attributeName, string $value, ?string $code = null): ProductAttributeValue
    {
        $attribute = ProductAttribute::factory()->create([
            'code' => $code ?? $attributeName,
            'slug' => str($code ?? $attributeName)->slug()->toString(),
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
            'categories' => Category::query()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categorySnapshot(Category $category): array
    {
        return $category->only([
            'name',
            'slug',
            'description',
            'image_path',
            'meta_title',
            'meta_description',
            'updated_at',
        ]);
    }
}
