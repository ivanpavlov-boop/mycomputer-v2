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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use JsonException;
use Tests\TestCase;

class CategorySpecificationTemplateCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('product-attributes:audit-category-template-coverage', $commands);
        $this->assertFalse($commands['product-attributes:audit-category-template-coverage']->getDefinition()->hasOption('apply'));
    }

    /**
     * @throws JsonException
     */
    public function test_audit_reports_direct_inherited_and_missing_template_coverage_as_json(): void
    {
        $laptops = Category::factory()->create(['name' => 'Laptopi', 'slug' => 'laptopi']);
        $monitors = Category::factory()->create(['name' => 'Monitors', 'slug' => 'monitori']);
        $gamingMonitors = Category::factory()->create([
            'name' => 'Gaming Monitors',
            'slug' => 'gaming-monitori',
            'parent_id' => $monitors->id,
        ]);
        $cables = Category::factory()->create(['name' => 'Cables', 'slug' => 'kabeli']);
        Category::factory()->create(['name' => 'Empty Category', 'slug' => 'empty-category']);

        $ram = $this->attribute('ram');
        $screenSize = $this->attribute('screen_size');

        CategoryProductAttribute::factory()->create([
            'category_id' => $laptops->id,
            'product_attribute_id' => $ram->id,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $monitors->id,
            'product_attribute_id' => $screenSize->id,
        ]);

        Product::factory()->supplierPublished()->count(2)->create(['category_id' => $laptops->id]);
        Product::factory()->supplierPublished()->create(['category_id' => $gamingMonitors->id]);
        Product::factory()->supplierPublished()->count(3)->create(['category_id' => $cables->id]);

        $payload = $this->coverageJson();
        $rows = collect($payload['rows'])->keyBy('category_slug');

        $this->assertSame(3, $payload['summary']['total_categories_checked']);
        $this->assertSame(3, $payload['summary']['categories_with_products']);
        $this->assertSame(1, $payload['summary']['categories_with_direct_templates']);
        $this->assertSame(1, $payload['summary']['categories_with_inherited_templates']);
        $this->assertSame(1, $payload['summary']['categories_without_templates']);
        $this->assertSame(2, $payload['summary']['products_covered_by_direct_templates']);
        $this->assertSame(1, $payload['summary']['products_covered_by_inherited_templates']);
        $this->assertSame(3, $payload['summary']['products_without_templates']);

        $this->assertSame('direct_template', $rows['laptopi']['coverage_status']);
        $this->assertSame(2, $rows['laptopi']['products_count']);
        $this->assertSame(1, $rows['laptopi']['direct_category_product_attributes_count']);
        $this->assertSame(0, $rows['laptopi']['inherited_category_product_attributes_count']);
        $this->assertSame(1, $rows['laptopi']['total_effective_expected_attributes_count']);
        $this->assertSame('keep', $rows['laptopi']['suggested_next_action']);

        $this->assertSame('inherited_template', $rows['gaming-monitori']['coverage_status']);
        $this->assertSame('Monitors', $rows['gaming-monitori']['parent_category']);
        $this->assertSame(0, $rows['gaming-monitori']['direct_category_product_attributes_count']);
        $this->assertSame(1, $rows['gaming-monitori']['inherited_category_product_attributes_count']);
        $this->assertSame(1, $rows['gaming-monitori']['total_effective_expected_attributes_count']);
        $this->assertSame('map to parent template', $rows['gaming-monitori']['suggested_next_action']);

        $this->assertSame('no_template', $rows['kabeli']['coverage_status']);
        $this->assertSame('cables', $rows['kabeli']['suggested_product_family']);
        $this->assertSame('create template', $rows['kabeli']['suggested_next_action']);
        $this->assertSame('kabeli', $payload['summary']['top_missing_template_categories'][0]['category_slug']);
        $this->assertSame(3, $payload['summary']['suggested_next_product_family_templates']['cables']['products_count']);
    }

    /**
     * @throws JsonException
     */
    public function test_only_missing_and_limit_apply_to_display_rows_while_summary_keeps_full_scan(): void
    {
        $direct = Category::factory()->create(['slug' => 'laptopi']);
        $missingOne = Category::factory()->create(['slug' => 'kabeli']);
        $missingTwo = Category::factory()->create(['slug' => 'unknown-family']);

        CategoryProductAttribute::factory()->create([
            'category_id' => $direct->id,
            'product_attribute_id' => $this->attribute('ram')->id,
        ]);

        Product::factory()->supplierPublished()->create(['category_id' => $direct->id]);
        Product::factory()->supplierPublished()->count(2)->create(['category_id' => $missingOne->id]);
        Product::factory()->supplierPublished()->create(['category_id' => $missingTwo->id]);

        $payload = $this->coverageJson([
            '--only-missing' => true,
            '--limit' => 1,
        ]);

        $this->assertCount(1, $payload['rows']);
        $this->assertSame('no_template', $payload['rows'][0]['coverage_status']);
        $this->assertSame('kabeli', $payload['rows'][0]['category_slug']);
        $this->assertSame(2, $payload['summary']['categories_without_templates']);
        $this->assertSame(3, $payload['summary']['products_without_templates']);
    }

    /**
     * @throws JsonException
     */
    public function test_family_inference_covers_known_categories_and_keeps_unknown_safe(): void
    {
        foreach ([
            'laptopi' => 'laptops',
            'monitori' => 'monitors',
            'procesori' => 'processors/cpu',
            'kabeli' => 'cables',
            'miscellaneous-parts' => 'unknown',
        ] as $slug => $family) {
            $category = Category::factory()->create(['slug' => $slug, 'name' => $slug]);
            Product::factory()->supplierPublished()->create(['category_id' => $category->id]);
        }

        $rows = collect($this->coverageJson()['rows'])->keyBy('category_slug');

        $this->assertSame('laptops', $rows['laptopi']['suggested_product_family']);
        $this->assertSame('monitors', $rows['monitori']['suggested_product_family']);
        $this->assertSame('processors/cpu', $rows['procesori']['suggested_product_family']);
        $this->assertSame('cables', $rows['kabeli']['suggested_product_family']);
        $this->assertSame('unknown', $rows['miscellaneous-parts']['suggested_product_family']);
        $this->assertSame('needs manual classification', $rows['miscellaneous-parts']['suggested_next_action']);
    }

    public function test_table_output_reports_summary_and_read_only_change_counters(): void
    {
        $category = Category::factory()->create(['name' => 'Processors', 'slug' => 'procesori']);
        Product::factory()->supplierPublished()->create(['category_id' => $category->id]);

        $this->assertSame(0, Artisan::call('product-attributes:audit-category-template-coverage', [
            '--format' => 'table',
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('Category specification template coverage audit', $output);
        $this->assertStringContainsString('no_template', $output);
        $this->assertStringContainsString('processors/cpu', $output);
        $this->assertStringContainsString('Total categories checked: 1', $output);
        $this->assertStringContainsString('Products without templates: 1', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('categories changed: 0', $output);
        $this->assertStringContainsString('category_product_attributes changed: 0', $output);
        $this->assertStringContainsString('product_attribute_values changed: 0', $output);
    }

    public function test_coverage_audit_is_read_only_and_preserves_catalog_and_staging_data(): void
    {
        $category = Category::factory()->create([
            'name' => 'Laptopi',
            'slug' => 'laptopi',
            'description' => 'Admin category description',
            'image_path' => 'categories/laptopi.jpg',
        ]);
        $attribute = $this->attribute('ram');
        $assignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_required' => true,
        ]);
        $product = Product::factory()->supplierPublished()->create([
            'category_id' => $category->id,
            'sku' => 'COVERAGE-SAFE-001',
        ]);
        $value = ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'value_text' => '16 GB',
            'custom_value' => '16 GB',
        ]);
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'supplier_sku' => 'COVERAGE-STAGED-001',
            'name' => 'Coverage staged supplier product',
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['RAM' => '16 GB']],
            'payload_hash' => 'coverage-staged-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $counts = $this->databaseCounts();
        $categorySnapshot = $category->fresh()->only(['name', 'slug', 'description', 'image_path', 'updated_at']);
        $productSnapshot = $product->fresh()->only(['category_id', 'name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']);
        $supplierProductSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);
        $assignmentSnapshot = $assignment->fresh()->only(['category_id', 'product_attribute_id', 'is_required', 'is_filterable', 'is_visible_on_product', 'is_comparable', 'sort_order', 'updated_at']);
        $valueSnapshot = $value->fresh()->only(['product_id', 'product_attribute_id', 'value_text', 'custom_value', 'updated_at']);

        $this->assertSame(0, Artisan::call('product-attributes:audit-category-template-coverage', [
            '--format' => 'json',
        ]));

        $this->assertSame($counts, $this->databaseCounts());
        $this->assertEquals($categorySnapshot, $category->fresh()->only(array_keys($categorySnapshot)));
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
        $this->assertEquals($supplierProductSnapshot, $supplierProduct->fresh()->only(array_keys($supplierProductSnapshot)));
        $this->assertEquals($assignmentSnapshot, $assignment->fresh()->only(array_keys($assignmentSnapshot)));
        $this->assertEquals($valueSnapshot, $value->fresh()->only(array_keys($valueSnapshot)));
    }

    public function test_coverage_audit_does_not_expand_sync_features_or_storefront_mutation_surfaces(): void
    {
        $category = Category::factory()->create(['slug' => 'laptopi']);
        Product::factory()->supplierPublished()->create(['category_id' => $category->id]);

        $this->assertSame(0, Artisan::call('product-attributes:audit-category-template-coverage'));

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->get('/cart')->assertNotFound();
    }

    private function attribute(string $code): ProductAttribute
    {
        return ProductAttribute::factory()->create([
            'code' => $code,
            'slug' => str($code)->slug()->toString(),
            'name' => str($code)->headline()->toString(),
            'name_bg' => str($code)->headline()->toString(),
            'type' => ProductAttribute::TYPE_TEXT,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function coverageJson(array $arguments = []): array
    {
        $this->assertSame(0, Artisan::call('product-attributes:audit-category-template-coverage', array_merge([
            '--format' => 'json',
            '--limit' => 50,
        ], $arguments)));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, int>
     */
    private function databaseCounts(): array
    {
        return [
            'products' => Product::query()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'categories' => Category::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
        ];
    }
}
