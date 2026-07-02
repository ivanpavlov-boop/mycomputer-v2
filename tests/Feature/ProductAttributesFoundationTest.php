<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeValues\AttributeValueResource;
use App\Filament\Resources\CategoryProductAttributes\CategoryProductAttributeResource;
use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductAttributesFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_attribute_core_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('product_attributes'));
        $this->assertTrue(Schema::hasTable('attribute_values'));
        $this->assertTrue(Schema::hasTable('category_product_attributes'));
        $this->assertTrue(Schema::hasTable('product_attribute_values'));

        foreach ([
            'code',
            'name_bg',
            'name_en',
            'description_bg',
            'description_en',
            'type',
            'unit',
            'is_filterable',
            'is_visible_on_product',
            'is_comparable',
            'is_required_by_default',
            'is_active',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('product_attributes', $column), "Missing product_attributes.{$column}");
        }

        foreach ([
            'category_id',
            'product_attribute_id',
            'is_required',
            'is_filterable',
            'is_visible_on_product',
            'is_comparable',
            'sort_order',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('category_product_attributes', $column), "Missing category_product_attributes.{$column}");
        }

        foreach ([
            'value_text',
            'value_number',
            'value_boolean',
            'value_json',
            'unit',
            'source',
            'is_verified',
            'sort_order',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('product_attribute_values', $column), "Missing product_attribute_values.{$column}");
        }
    }

    public function test_product_attribute_code_is_unique_and_types_are_limited(): void
    {
        $group = AttributeGroup::factory()->create();

        ProductAttribute::query()->create([
            'attribute_group_id' => $group->id,
            'code' => 'ram',
            'name_bg' => 'RAM',
            'slug' => 'ram',
            'type' => ProductAttribute::TYPE_SELECT,
            'is_active' => true,
        ]);

        $this->assertContains(ProductAttribute::TYPE_TEXT, ProductAttribute::TYPES);
        $this->assertContains(ProductAttribute::TYPE_SELECT, ProductAttribute::TYPES);
        $this->assertContains(ProductAttribute::TYPE_MULTISELECT, ProductAttribute::TYPES);
        $this->assertContains(ProductAttribute::TYPE_DECIMAL, ProductAttribute::TYPES);
        $this->assertContains(ProductAttribute::TYPE_JSON, ProductAttribute::TYPES);

        $this->expectException(QueryException::class);

        ProductAttribute::query()->create([
            'attribute_group_id' => $group->id,
            'code' => 'ram',
            'name_bg' => 'Duplicate RAM',
            'slug' => 'duplicate-ram',
            'type' => ProductAttribute::TYPE_TEXT,
            'is_active' => true,
        ]);
    }

    public function test_options_category_assignments_and_product_values_have_relations(): void
    {
        $category = Category::factory()->create();
        $attribute = ProductAttribute::factory()->create([
            'code' => 'ssd_capacity',
            'name_bg' => 'SSD капацитет',
            'type' => ProductAttribute::TYPE_SELECT,
            'unit' => 'GB',
        ]);
        $option = AttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'value' => '512 GB',
            'slug' => '512gb',
        ]);
        $categoryAssignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_required' => true,
            'is_filterable' => true,
            'is_visible_on_product' => true,
        ]);
        $productValue = ProductAttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => $option->id,
            'custom_value' => null,
            'value_number' => 512,
            'unit' => 'GB',
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_verified' => true,
        ]);

        $this->assertTrue($attribute->values()->whereKey($option->id)->exists());
        $this->assertTrue($category->productAttributeAssignments()->whereKey($categoryAssignment->id)->exists());
        $this->assertTrue($attribute->categoryAssignments()->whereKey($categoryAssignment->id)->exists());
        $this->assertSame($attribute->id, $productValue->fresh()->attribute->id);
        $this->assertSame($option->id, $productValue->fresh()->value->id);
        $this->assertTrue($productValue->fresh()->is_verified);
    }

    public function test_attribute_foundation_does_not_mutate_products_or_supplier_products(): void
    {
        $product = Product::factory()->supplierPublished()->create([
            'sku' => 'ATTR-SAFE-001',
            'name' => 'Existing Catalog Product',
        ]);
        $supplier = Supplier::factory()->create();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'ATTR-STAGED-001',
            'name' => 'Existing Staged Product',
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['RAM' => '16 GB']],
            'payload_hash' => 'attr-safe-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $productSnapshot = $product->fresh()->only(['name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']);
        $supplierProductSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);

        $attribute = ProductAttribute::factory()->create([
            'code' => 'warranty',
            'name_bg' => 'Гаранция',
            'type' => ProductAttribute::TYPE_NUMBER,
            'unit' => 'месеца',
        ]);
        AttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'value' => '24 месеца',
            'slug' => '24-months',
        ]);

        $this->assertSame(1, Product::query()->where('sku', 'ATTR-SAFE-001')->count());
        $this->assertSame(1, SupplierProduct::query()->where('supplier_sku', 'ATTR-STAGED-001')->count());
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
        $this->assertEquals($supplierProductSnapshot, $supplierProduct->fresh()->only(array_keys($supplierProductSnapshot)));
    }

    public function test_product_attribute_admin_resources_follow_existing_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER_AUDITOR,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin);

        $this->assertTrue(ProductAttributeResource::canViewAny());
        $this->assertTrue(AttributeValueResource::canViewAny());
        $this->assertTrue(CategoryProductAttributeResource::canViewAny());

        $this->get(ProductAttributeResource::getUrl())->assertOk()->assertSee('Характеристики');
        $this->get(AttributeValueResource::getUrl())->assertOk()->assertSee('Опции');
        $this->get(CategoryProductAttributeResource::getUrl())->assertOk()->assertSee('Категорийни характеристики');

        $this->actingAs($viewer);

        $this->assertFalse(ProductAttributeResource::canCreate());
        $this->assertFalse(AttributeValueResource::canCreate());
        $this->assertFalse(CategoryProductAttributeResource::canCreate());
    }

    public function test_storefront_and_catalog_sync_safety_remain_unchanged(): void
    {
        $category = Category::factory()->create(['slug' => 'attribute-safe-category', 'is_active' => true]);
        $product = Product::factory()->supplierPublished()->create([
            'category_id' => $category->id,
            'slug' => 'attribute-safe-product',
            'sku' => 'ATTR-PUBLIC-001',
        ]);

        $this->getJson('/api/v1/products?per_page=10')
            ->assertOk()
            ->assertJsonFragment(['sku' => $product->sku]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonFragment(['slug' => $category->slug]);

        $this->getJson('/api/v1/categories/'.$category->slug.'/products')
            ->assertOk()
            ->assertJsonFragment(['sku' => $product->sku]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.sku', $product->sku);

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }
}
