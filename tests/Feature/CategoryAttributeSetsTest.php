<?php

namespace Tests\Feature;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryAttributeSetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_attribute_sets_dry_run_does_not_mutate_database(): void
    {
        $this->seedStarterAttributes();

        $category = Category::factory()->create([
            'name' => 'Laptopi',
            'slug' => 'laptopi',
            'description' => 'Curated category description',
            'image_path' => 'categories/laptopi.jpg',
            'meta_title' => 'Laptopi SEO',
            'meta_description' => 'Laptopi SEO description',
        ]);
        ProductAttribute::query()->where('code', 'gpu')->firstOrFail()->delete();

        $product = Product::factory()->supplierPublished()->create([
            'category_id' => $category->id,
            'sku' => 'CAT-ATTR-SAFE-001',
        ]);
        $supplier = Supplier::factory()->create();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'CAT-ATTR-STAGED-001',
            'name' => 'Existing staged product',
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['RAM' => '16 GB']],
            'payload_hash' => 'category-attribute-set-safe-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $categorySnapshot = $this->categorySnapshot($category);
        $productSnapshot = $product->fresh()->only(['category_id', 'name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']);
        $supplierProductSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);
        $attributeCount = ProductAttribute::query()->count();
        $optionCount = AttributeValue::query()->count();

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--set' => 'laptops']));
        $output = Artisan::output();

        $this->assertStringContainsString('Dry-run only. No records were changed.', $output);
        $this->assertStringContainsString('Category sets considered: 1', $output);
        $this->assertStringContainsString('Categories found: 1', $output);
        $this->assertStringContainsString('Categories skipped: 0', $output);
        $this->assertStringContainsString('Assignments to create: 12', $output);
        $this->assertStringContainsString('Attributes missing: 1', $output);
        $this->assertStringContainsString('Missing attribute for set "laptops" and category "laptopi": gpu.', $output);
        $this->assertStringContainsString('Products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('product_attribute_values created: 0', $output);

        $this->assertSame(0, CategoryProductAttribute::query()->count());
        $this->assertSame(0, ProductAttributeValue::query()->count());
        $this->assertSame($attributeCount, ProductAttribute::query()->count());
        $this->assertSame($optionCount, AttributeValue::query()->count());
        $this->assertEquals($categorySnapshot, $this->categorySnapshot($category->fresh()));
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
        $this->assertEquals($supplierProductSnapshot, $supplierProduct->fresh()->only(array_keys($supplierProductSnapshot)));
    }

    public function test_category_attribute_sets_apply_creates_assignments_and_is_idempotent(): void
    {
        $this->seedStarterAttributes();
        $category = Category::factory()->create(['slug' => 'laptopi']);
        $attributeCount = ProductAttribute::query()->count();
        $optionCount = AttributeValue::query()->count();

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--set' => 'laptops', '--apply' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Category attribute sets applied.', $output);
        $this->assertStringContainsString('Assignments to create: 13', $output);
        $this->assertSame(13, CategoryProductAttribute::query()->where('category_id', $category->id)->count());

        $ram = ProductAttribute::query()->where('code', 'ram')->firstOrFail();
        $this->assertDatabaseHas('category_product_attributes', [
            'category_id' => $category->id,
            'product_attribute_id' => $ram->id,
            'is_required' => false,
            'is_filterable' => true,
            'is_visible_on_product' => true,
            'is_comparable' => true,
            'sort_order' => 20,
        ]);

        $assignmentCount = CategoryProductAttribute::query()->count();

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--set' => 'laptops', '--apply' => true]));
        $secondOutput = Artisan::output();

        $this->assertStringContainsString('Assignments to create: 0', $secondOutput);
        $this->assertStringContainsString('Assignments already present: 13', $secondOutput);
        $this->assertSame($assignmentCount, CategoryProductAttribute::query()->count());
        $this->assertSame($attributeCount, ProductAttribute::query()->count());
        $this->assertSame($optionCount, AttributeValue::query()->count());
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_category_attribute_sets_skip_missing_categories_and_attributes(): void
    {
        $this->seedStarterAttributes();
        Category::factory()->create(['slug' => 'laptopi']);
        ProductAttribute::query()->where('code', 'gpu')->firstOrFail()->delete();

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets'));
        $output = Artisan::output();

        $this->assertStringContainsString('Category sets considered: 8', $output);
        $this->assertStringContainsString('Categories found: 1', $output);
        $this->assertStringContainsString('Categories skipped: 7', $output);
        $this->assertStringContainsString('Attributes missing: 1', $output);
        $this->assertStringContainsString('Skipped category set "monitors"', $output);
        $this->assertStringContainsString('Missing attribute for set "laptops" and category "laptopi": gpu.', $output);
        $this->assertSame(0, CategoryProductAttribute::query()->count());
    }

    public function test_category_attribute_sets_do_not_overwrite_existing_admin_assignment_flags(): void
    {
        $this->seedStarterAttributes();
        $category = Category::factory()->create(['slug' => 'laptopi']);
        $ram = ProductAttribute::query()->where('code', 'ram')->firstOrFail();

        $assignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $ram->id,
            'is_required' => true,
            'is_filterable' => false,
            'is_visible_on_product' => false,
            'is_comparable' => false,
            'sort_order' => 999,
        ]);

        $snapshot = $assignment->fresh()->only([
            'is_required',
            'is_filterable',
            'is_visible_on_product',
            'is_comparable',
            'sort_order',
        ]);

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--set' => 'laptops', '--apply' => true]));

        $this->assertEquals($snapshot, $assignment->fresh()->only(array_keys($snapshot)));
        $this->assertSame(13, CategoryProductAttribute::query()->where('category_id', $category->id)->count());
    }

    public function test_category_attribute_sets_reuse_refresh_rate_slug_code_mismatch(): void
    {
        $group = AttributeGroup::factory()->create();
        $now = now();

        $legacyAttributeId = DB::table('product_attributes')->insertGetId([
            'attribute_group_id' => $group->id,
            'code' => 'refresh-rate',
            'name' => 'Legacy refresh rate',
            'name_bg' => 'Legacy refresh rate',
            'name_en' => null,
            'slug' => 'refresh-rate',
            'type' => ProductAttribute::TYPE_SELECT,
            'unit' => 'Hz',
            'sort_order' => 5,
            'is_filterable' => true,
            'is_required' => false,
            'is_visible_on_product' => true,
            'is_comparable' => true,
            'is_required_by_default' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->seedStarterAttributes();
        $category = Category::factory()->create(['slug' => 'monitori']);

        $this->assertSame(0, ProductAttribute::query()->where('code', 'refresh_rate')->count());
        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--set' => 'monitors', '--apply' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('requested code "refresh_rate" matched existing code "refresh-rate" with slug "refresh-rate"', $output);
        $this->assertDatabaseHas('category_product_attributes', [
            'category_id' => $category->id,
            'product_attribute_id' => $legacyAttributeId,
        ]);
        $this->assertSame(1, ProductAttribute::query()->where('slug', 'refresh-rate')->count());
        $this->assertSame(0, ProductAttribute::query()->where('code', 'refresh_rate')->count());
    }

    public function test_category_attribute_sets_filters_and_list_are_safe(): void
    {
        $this->seedStarterAttributes();
        $laptop = Category::factory()->create(['slug' => 'laptopi']);
        $phone = Category::factory()->create(['slug' => 'iphone']);

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--list' => true]));
        $listOutput = Artisan::output();

        $this->assertStringContainsString('Configured category attribute sets:', $listOutput);
        $this->assertStringContainsString('- laptops', $listOutput);
        $this->assertStringContainsString('aliases: laptopi, laptops, notebooks, laptop, noutbutsi, noutbuci', $listOutput);
        $this->assertSame(0, CategoryProductAttribute::query()->count());

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--category' => 'iphone', '--apply' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Category sets considered: 1', $output);
        $this->assertStringContainsString('Categories found: 1', $output);
        $this->assertStringContainsString('Assignments to create: 9', $output);
        $this->assertSame(9, CategoryProductAttribute::query()->where('category_id', $phone->id)->count());
        $this->assertSame(0, CategoryProductAttribute::query()->where('category_id', $laptop->id)->count());
    }

    public function test_category_attribute_sets_do_not_create_catalog_data_or_expand_sync_features(): void
    {
        $this->seedStarterAttributes();
        $category = Category::factory()->create(['slug' => 'laptopi']);
        $categorySnapshot = $this->categorySnapshot($category);
        $product = Product::factory()->supplierPublished()->create(['category_id' => $category->id]);
        $supplier = Supplier::factory()->create();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'CAT-ATTR-SYNC-SAFE-001',
            'name' => 'Staged product',
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['Screen' => '15.6']],
            'payload_hash' => 'category-attribute-set-sync-safe-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $productSnapshot = $product->fresh()->only(['category_id', 'name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']);
        $supplierProductSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);
        $attributeCount = ProductAttribute::query()->count();
        $optionCount = AttributeValue::query()->count();

        $this->assertSame(0, Artisan::call('product-attributes:assign-category-sets', ['--set' => 'laptops', '--apply' => true]));

        $this->assertEquals($categorySnapshot, $this->categorySnapshot($category->fresh()));
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
        $this->assertEquals($supplierProductSnapshot, $supplierProduct->fresh()->only(array_keys($supplierProductSnapshot)));
        $this->assertSame($attributeCount, ProductAttribute::query()->count());
        $this->assertSame($optionCount, AttributeValue::query()->count());
        $this->assertSame(0, ProductAttributeValue::query()->count());
        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    private function seedStarterAttributes(): void
    {
        $this->assertSame(0, Artisan::call('product-attributes:seed-starter', ['--apply' => true]));
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
