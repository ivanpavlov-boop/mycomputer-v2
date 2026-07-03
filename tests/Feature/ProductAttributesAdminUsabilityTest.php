<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeValues\AttributeValueResource;
use App\Filament\Resources\AttributeValues\Pages\ListAttributeValues;
use App\Filament\Resources\CategoryProductAttributes\CategoryProductAttributeResource;
use App\Filament\Resources\CategoryProductAttributes\Pages\ListCategoryProductAttributes;
use App\Filament\Resources\ProductAttributes\Pages\CreateProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\ListProductAttributes;
use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ProductAttributesAdminUsabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_admin_resources_have_bulgarian_labels_columns_and_filters(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertSame('Характеристики', ProductAttributeResource::getNavigationLabel());
        $this->assertSame('Опции на характеристики', AttributeValueResource::getNavigationLabel());
        $this->assertSame('Категорийни характеристики', CategoryProductAttributeResource::getNavigationLabel());

        $productAttributeColumns = array_keys(Livewire::test(ListProductAttributes::class)->instance()->getTable()->getColumns());
        $this->assertContains('code', $productAttributeColumns);
        $this->assertContains('name_bg', $productAttributeColumns);
        $this->assertContains('name_en', $productAttributeColumns);
        $this->assertContains('type', $productAttributeColumns);
        $this->assertContains('unit', $productAttributeColumns);
        $this->assertContains('is_filterable', $productAttributeColumns);
        $this->assertContains('is_visible_on_product', $productAttributeColumns);
        $this->assertContains('is_comparable', $productAttributeColumns);
        $this->assertContains('is_required_by_default', $productAttributeColumns);
        $this->assertContains('is_active', $productAttributeColumns);
        $this->assertContains('sort_order', $productAttributeColumns);

        $productAttributeFilters = array_keys(Livewire::test(ListProductAttributes::class)->instance()->getTable()->getFilters());
        $this->assertContains('type', $productAttributeFilters);
        $this->assertContains('is_filterable', $productAttributeFilters);
        $this->assertContains('is_visible_on_product', $productAttributeFilters);
        $this->assertContains('is_comparable', $productAttributeFilters);
        $this->assertContains('is_required_by_default', $productAttributeFilters);
        $this->assertContains('is_active', $productAttributeFilters);

        $attributeValueColumns = array_keys(Livewire::test(ListAttributeValues::class)->instance()->getTable()->getColumns());
        $this->assertContains('attribute.code', $attributeValueColumns);
        $this->assertContains('attribute.name', $attributeValueColumns);
        $this->assertContains('slug', $attributeValueColumns);
        $this->assertContains('value', $attributeValueColumns);
        $this->assertContains('value_translations.en', $attributeValueColumns);
        $this->assertContains('is_active', $attributeValueColumns);
        $this->assertContains('sort_order', $attributeValueColumns);

        $categoryAttributeColumns = array_keys(Livewire::test(ListCategoryProductAttributes::class)->instance()->getTable()->getColumns());
        $this->assertContains('category.name', $categoryAttributeColumns);
        $this->assertContains('category.slug', $categoryAttributeColumns);
        $this->assertContains('attribute.code', $categoryAttributeColumns);
        $this->assertContains('attribute.name', $categoryAttributeColumns);
        $this->assertContains('is_required', $categoryAttributeColumns);
        $this->assertContains('is_filterable', $categoryAttributeColumns);
        $this->assertContains('is_visible_on_product', $categoryAttributeColumns);
        $this->assertContains('is_comparable', $categoryAttributeColumns);
        $this->assertContains('sort_order', $categoryAttributeColumns);
    }

    public function test_viewer_auditor_remains_read_only_for_attribute_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER_AUDITOR,
            'is_active' => true,
        ]);

        $this->actingAs($viewer);

        $this->assertFalse(ProductAttributeResource::canCreate());
        $this->assertFalse(AttributeValueResource::canCreate());
        $this->assertFalse(CategoryProductAttributeResource::canCreate());
    }

    public function test_product_attribute_form_enforces_safe_code_and_type_values(): void
    {
        $this->actingAsSuperAdmin();

        $group = AttributeGroup::factory()->create();

        Livewire::test(CreateProductAttribute::class)
            ->fillForm([
                'attribute_group_id' => $group->id,
                'type' => ProductAttribute::TYPE_SELECT,
                'code' => 'ssd_capacity',
                'name_bg' => 'SSD капацитет',
                'unit' => 'GB',
                'sort_order' => 10,
                'is_filterable' => true,
                'is_visible_on_product' => true,
                'is_comparable' => true,
                'is_required_by_default' => false,
                'is_required' => false,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_attributes', [
            'code' => 'ssd_capacity',
            'name_bg' => 'SSD капацитет',
            'type' => ProductAttribute::TYPE_SELECT,
        ]);

        Livewire::test(CreateProductAttribute::class)
            ->fillForm([
                'attribute_group_id' => $group->id,
                'type' => 'unsafe_type',
                'code' => 'Unsafe Code',
                'name_bg' => 'Невалидна характеристика',
            ])
            ->call('create')
            ->assertHasFormErrors(['type', 'code']);
    }

    public function test_starter_command_dry_run_does_not_create_records(): void
    {
        $status = Artisan::call('product-attributes:seed-starter');

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Dry-run only. No records were changed.', Artisan::output());
        $this->assertSame(0, ProductAttribute::query()->count());
        $this->assertSame(0, AttributeValue::query()->count());
        $this->assertSame(0, CategoryProductAttribute::query()->count());
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_starter_command_apply_creates_attributes_options_and_is_idempotent(): void
    {
        $status = Artisan::call('product-attributes:seed-starter', ['--apply' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Starter product attributes applied.', Artisan::output());
        $this->assertSame(18, ProductAttribute::query()->count());
        $this->assertDatabaseHas('product_attributes', [
            'code' => 'ram',
            'name_bg' => 'RAM',
            'type' => ProductAttribute::TYPE_SELECT,
            'unit' => 'GB',
            'is_filterable' => true,
            'is_visible_on_product' => true,
            'is_comparable' => true,
        ]);
        $this->assertDatabaseHas('product_attributes', [
            'code' => 'weight',
            'name_bg' => 'Тегло',
            'unit' => 'kg',
        ]);

        $ram = ProductAttribute::query()->where('code', 'ram')->firstOrFail();
        $this->assertDatabaseHas('attribute_values', [
            'product_attribute_id' => $ram->id,
            'value' => '16 GB',
            'slug' => '16-gb',
        ]);

        $attributeCount = ProductAttribute::query()->count();
        $optionCount = AttributeValue::query()->count();

        $this->assertSame(0, Artisan::call('product-attributes:seed-starter', ['--apply' => true]));

        $this->assertSame($attributeCount, ProductAttribute::query()->count());
        $this->assertSame($optionCount, AttributeValue::query()->count());
        $this->assertSame(0, CategoryProductAttribute::query()->count());
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_starter_command_does_not_overwrite_existing_admin_labels(): void
    {
        $group = AttributeGroup::factory()->create();

        ProductAttribute::factory()->create([
            'attribute_group_id' => $group->id,
            'code' => 'ram',
            'name' => 'Curated RAM',
            'name_bg' => 'Curated RAM',
            'name_en' => 'Curated RAM EN',
            'slug' => 'custom-ram',
            'type' => ProductAttribute::TYPE_TEXT,
            'is_filterable' => false,
        ]);

        $this->assertSame(0, Artisan::call('product-attributes:seed-starter', ['--apply' => true]));

        $ram = ProductAttribute::query()->where('code', 'ram')->firstOrFail();

        $this->assertSame('Curated RAM', $ram->name_bg);
        $this->assertSame('Curated RAM EN', $ram->name_en);
        $this->assertSame(ProductAttribute::TYPE_TEXT, $ram->type);
        $this->assertFalse($ram->is_filterable);
        $this->assertTrue($ram->values()->where('slug', '16-gb')->exists());
    }

    public function test_starter_command_reuses_existing_slug_with_different_code(): void
    {
        $group = AttributeGroup::factory()->create();
        $now = now();

        $attributeId = DB::table('product_attributes')->insertGetId([
            'attribute_group_id' => $group->id,
            'code' => 'refresh-rate',
            'name' => 'Custom refresh rate',
            'name_bg' => 'Custom refresh rate',
            'name_en' => null,
            'slug' => 'refresh-rate',
            'type' => ProductAttribute::TYPE_SELECT,
            'unit' => null,
            'sort_order' => 5,
            'is_filterable' => false,
            'is_required' => false,
            'is_visible_on_product' => true,
            'is_comparable' => false,
            'is_required_by_default' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AttributeValue::query()->create([
            'product_attribute_id' => $attributeId,
            'value' => '60 Hz',
            'value_translations' => ['en' => 'Custom 60 Hz'],
            'slug' => 'custom-60hz',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        $attributeBeforeDryRun = ProductAttribute::query()->findOrFail($attributeId)->only([
            'code',
            'name',
            'name_bg',
            'name_en',
            'slug',
            'type',
            'unit',
            'sort_order',
            'is_filterable',
            'is_comparable',
        ]);
        $optionCountBeforeDryRun = AttributeValue::query()->count();

        $this->assertSame(0, Artisan::call('product-attributes:seed-starter'));
        $dryRunOutput = Artisan::output();

        $this->assertStringContainsString('Dry-run only. No records were changed.', $dryRunOutput);
        $this->assertStringContainsString('Attribute slug/code mismatches reused: 1', $dryRunOutput);
        $this->assertStringContainsString('starter code "refresh_rate" maps to existing code "refresh-rate" with slug "refresh-rate"', $dryRunOutput);
        $this->assertEquals($attributeBeforeDryRun, ProductAttribute::query()->findOrFail($attributeId)->only(array_keys($attributeBeforeDryRun)));
        $this->assertSame($optionCountBeforeDryRun, AttributeValue::query()->count());

        $this->assertSame(0, Artisan::call('product-attributes:seed-starter', ['--apply' => true]));
        $applyOutput = Artisan::output();

        $this->assertStringContainsString('Starter product attributes applied.', $applyOutput);
        $this->assertStringContainsString('Attribute slug/code mismatches reused: 1', $applyOutput);

        $attribute = ProductAttribute::query()->findOrFail($attributeId);

        $this->assertSame('refresh-rate', $attribute->code);
        $this->assertSame('refresh-rate', $attribute->slug);
        $this->assertSame('Custom refresh rate', $attribute->name);
        $this->assertSame('Custom refresh rate', $attribute->name_bg);
        $this->assertNull($attribute->name_en);
        $this->assertSame(ProductAttribute::TYPE_SELECT, $attribute->type);
        $this->assertSame('Hz', $attribute->unit);

        $this->assertSame(18, ProductAttribute::query()->count());
        $this->assertSame(1, ProductAttribute::query()->where('slug', 'refresh-rate')->count());
        $this->assertSame(0, ProductAttribute::query()->where('code', 'refresh_rate')->count());
        $this->assertSame(1, AttributeValue::query()->where('product_attribute_id', $attributeId)->where('value', '60 Hz')->count());
        $this->assertFalse(AttributeValue::query()->where('product_attribute_id', $attributeId)->where('slug', '60-hz')->exists());

        $attributeCount = ProductAttribute::query()->count();
        $optionCount = AttributeValue::query()->count();

        $this->assertSame(0, Artisan::call('product-attributes:seed-starter', ['--apply' => true]));

        $this->assertSame($attributeCount, ProductAttribute::query()->count());
        $this->assertSame($optionCount, AttributeValue::query()->count());
        $this->assertSame(0, CategoryProductAttribute::query()->count());
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_starter_command_does_not_mutate_products_supplier_products_or_public_sync_safety(): void
    {
        $product = Product::factory()->supplierPublished()->create([
            'sku' => 'ATTR-STARTER-SAFE-001',
            'name' => 'Existing Product',
        ]);
        $supplier = Supplier::factory()->create();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'ATTR-STARTER-STAGED-001',
            'name' => 'Existing Staged Product',
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['RAM' => '16 GB']],
            'payload_hash' => 'attr-starter-safe-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $productSnapshot = $product->fresh()->only(['name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']);
        $supplierProductSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);

        $this->assertSame(0, Artisan::call('product-attributes:seed-starter', ['--apply' => true]));

        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
        $this->assertEquals($supplierProductSnapshot, $supplierProduct->fresh()->only(array_keys($supplierProductSnapshot)));
        $this->assertSame(0, ProductAttributeValue::query()->count());
        $this->assertSame(0, CategoryProductAttribute::query()->count());

        if (Schema::hasTable('supplier_product_attributes')) {
            $this->assertSame(0, DB::table('supplier_product_attributes')->count());
        }

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }
}
