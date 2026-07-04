<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\ProductAttributeValuesRelationManager;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ProductAttributeValuesAdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_resource_registers_manual_characteristics_relation_manager(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertContains(ProductAttributeValuesRelationManager::class, ProductResource::getRelations());

        $product = Product::factory()->create();

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('Характеристики');
    }

    public function test_super_admin_can_create_edit_and_delete_manual_product_attribute_value(): void
    {
        $this->actingAsSuperAdmin();

        $product = Product::factory()->create();
        $attribute = ProductAttribute::factory()->create([
            'code' => 'ram',
            'name' => 'RAM',
            'name_bg' => 'RAM',
            'type' => ProductAttribute::TYPE_TEXT,
            'unit' => 'GB',
            'is_filterable' => true,
        ]);

        $component = $this->relationManager($product);

        $component
            ->callTableAction('create', null, [
                'product_attribute_id' => $attribute->id,
                'value_text' => '16',
                'unit' => 'GB',
                'source' => ProductAttributeValue::SOURCE_MANUAL,
                'is_verified' => true,
                'is_filterable' => true,
                'sort_order' => 10,
            ])
            ->assertHasNoTableActionErrors();

        $value = ProductAttributeValue::query()->sole();

        $this->assertSame($product->id, $value->product_id);
        $this->assertSame($attribute->id, $value->product_attribute_id);
        $this->assertSame(ProductAttributeValue::SOURCE_MANUAL, $value->source);
        $this->assertSame('16', $value->value_text);
        $this->assertSame('16', $value->custom_value);
        $this->assertSame('GB', $value->unit);
        $this->assertTrue($value->is_verified);

        $component
            ->callTableAction('edit', $value, [
                'product_attribute_id' => $attribute->id,
                'value_text' => '32',
                'unit' => 'GB',
                'source' => ProductAttributeValue::SOURCE_MANUAL,
                'is_verified' => false,
                'is_filterable' => true,
                'sort_order' => 20,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('product_attribute_values', [
            'id' => $value->id,
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'value_text' => '32',
            'custom_value' => '32',
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_verified' => false,
            'sort_order' => 20,
        ]);

        $component->callTableAction('delete', $value);

        $this->assertDatabaseMissing('product_attribute_values', [
            'id' => $value->id,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_attributes', ['id' => $attribute->id]);
    }

    public function test_validation_is_type_aware_and_prevents_duplicates_and_wrong_options(): void
    {
        $this->actingAsSuperAdmin();

        $product = Product::factory()->create();
        $numberAttribute = ProductAttribute::factory()->create([
            'type' => ProductAttribute::TYPE_NUMBER,
            'code' => 'weight',
            'unit' => 'kg',
        ]);
        $selectAttribute = ProductAttribute::factory()->create([
            'type' => ProductAttribute::TYPE_SELECT,
            'code' => 'color',
        ]);
        $otherSelectAttribute = ProductAttribute::factory()->create([
            'type' => ProductAttribute::TYPE_SELECT,
            'code' => 'panel_type',
        ]);
        $wrongOption = AttributeValue::factory()->create([
            'product_attribute_id' => $otherSelectAttribute->id,
            'value' => 'IPS',
        ]);

        $this->relationManager($product)
            ->callTableAction('create', null, [
                'product_attribute_id' => $numberAttribute->id,
                'value_number' => 'not numeric',
                'source' => ProductAttributeValue::SOURCE_MANUAL,
            ])
            ->assertHasTableActionErrors(['value_number']);

        $this->relationManager($product)
            ->callTableAction('create', null, [
                'product_attribute_id' => $selectAttribute->id,
                'attribute_value_id' => $wrongOption->id,
                'source' => ProductAttributeValue::SOURCE_MANUAL,
            ])
            ->assertHasTableActionErrors(['attribute_value_id']);

        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $numberAttribute->id,
            'value_text' => null,
            'value_number' => '1.5000',
            'custom_value' => '1.5',
        ]);

        $this->relationManager($product)
            ->callTableAction('create', null, [
                'product_attribute_id' => $numberAttribute->id,
                'value_number' => 2,
                'source' => ProductAttributeValue::SOURCE_MANUAL,
            ])
            ->assertHasTableActionErrors();

        $this->assertSame(1, ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('product_attribute_id', $numberAttribute->id)
            ->count());
    }

    public function test_select_multiselect_and_boolean_values_use_existing_options_without_creating_new_options(): void
    {
        $this->actingAsSuperAdmin();

        $product = Product::factory()->create();
        $selectAttribute = ProductAttribute::factory()->create([
            'type' => ProductAttribute::TYPE_SELECT,
            'code' => 'color',
        ]);
        $multiselectAttribute = ProductAttribute::factory()->create([
            'type' => ProductAttribute::TYPE_MULTISELECT,
            'code' => 'connectors',
        ]);
        $booleanAttribute = ProductAttribute::factory()->create([
            'type' => ProductAttribute::TYPE_BOOLEAN,
            'code' => 'has_wifi',
        ]);
        $black = AttributeValue::factory()->create([
            'product_attribute_id' => $selectAttribute->id,
            'value' => 'Black',
        ]);
        $usb = AttributeValue::factory()->create([
            'product_attribute_id' => $multiselectAttribute->id,
            'value' => 'USB-C',
        ]);
        $hdmi = AttributeValue::factory()->create([
            'product_attribute_id' => $multiselectAttribute->id,
            'value' => 'HDMI',
        ]);

        $attributeCount = ProductAttribute::query()->count();
        $optionCount = AttributeValue::query()->count();

        $this->relationManager($product)
            ->callTableAction('create', null, [
                'product_attribute_id' => $selectAttribute->id,
                'attribute_value_id' => $black->id,
                'source' => ProductAttributeValue::SOURCE_MANUAL,
            ])
            ->assertHasNoTableActionErrors();

        $this->relationManager($product)
            ->callTableAction('create', null, [
                'product_attribute_id' => $multiselectAttribute->id,
                'selected_attribute_value_ids' => [$usb->id, $hdmi->id],
                'source' => ProductAttributeValue::SOURCE_MANUAL,
            ])
            ->assertHasNoTableActionErrors();

        $this->relationManager($product)
            ->callTableAction('create', null, [
                'product_attribute_id' => $booleanAttribute->id,
                'value_boolean' => true,
                'source' => ProductAttributeValue::SOURCE_MANUAL,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame($attributeCount, ProductAttribute::query()->count());
        $this->assertSame($optionCount, AttributeValue::query()->count());

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $selectAttribute->id,
            'attribute_value_id' => $black->id,
            'custom_value' => 'Black',
        ]);
        $this->assertEqualsCanonicalizing(
            [$usb->id, $hdmi->id],
            ProductAttributeValue::query()
                ->where('product_id', $product->id)
                ->where('product_attribute_id', $multiselectAttribute->id)
                ->firstOrFail()
                ->value_json['attribute_value_ids']
        );
        $this->assertTrue(ProductAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('product_attribute_id', $booleanAttribute->id)
            ->firstOrFail()
            ->value_boolean);
    }

    public function test_category_product_attributes_are_suggested_first_without_changing_category_assignments(): void
    {
        $this->actingAsSuperAdmin();

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $suggested = ProductAttribute::factory()->create([
            'name_bg' => 'RAM',
            'code' => 'ram',
            'type' => ProductAttribute::TYPE_SELECT,
            'sort_order' => 50,
        ]);
        $fallback = ProductAttribute::factory()->create([
            'name_bg' => 'Color',
            'code' => 'color',
            'type' => ProductAttribute::TYPE_TEXT,
            'sort_order' => 1,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $suggested->id,
            'sort_order' => 1,
        ]);

        $categoryAssignmentCount = CategoryProductAttribute::query()->count();

        $options = $this->relationManager($product)
            ->instance()
            ->attributeOptionsForProduct($product);

        $this->assertSame([$suggested->id, $fallback->id], array_keys($options));
        $this->assertStringStartsWith('Категория:', $options[$suggested->id]);
        $this->assertStringStartsWith('Всички:', $options[$fallback->id]);
        $this->assertSame($categoryAssignmentCount, CategoryProductAttribute::query()->count());
        $this->assertSame($category->id, $product->fresh()->category_id);
    }

    public function test_category_driven_editor_saves_only_filled_assigned_values(): void
    {
        $this->actingAsSuperAdmin();

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $ram = ProductAttribute::factory()->create([
            'code' => 'ram',
            'name_bg' => 'RAM',
            'type' => ProductAttribute::TYPE_TEXT,
            'unit' => 'GB',
            'is_filterable' => true,
        ]);
        $warranty = ProductAttribute::factory()->create([
            'code' => 'warranty_months',
            'name_bg' => 'Warranty',
            'type' => ProductAttribute::TYPE_NUMBER,
            'unit' => 'months',
        ]);
        $color = ProductAttribute::factory()->create([
            'code' => 'color',
            'name_bg' => 'Color',
            'type' => ProductAttribute::TYPE_SELECT,
        ]);

        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $ram->id,
            'is_required' => true,
            'sort_order' => 1,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $warranty->id,
            'sort_order' => 2,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $color->id,
            'sort_order' => 3,
        ]);

        $component = $this->relationManager($product);
        $rows = $component->instance()->categorySpecificationRowsForProduct($product);

        $this->assertCount(3, $rows);
        $this->assertSame([$ram->id, $warranty->id, $color->id], array_column($rows, 'product_attribute_id'));
        $this->assertTrue($rows[0]['is_required']);

        $component
            ->assertTableHeaderActionsExistInOrder(['saveCategorySpecifications', 'create'])
            ->callTableAction('saveCategorySpecifications', null, [
                'specifications' => [
                    $ram->id => [
                        'value_text' => '16',
                        'unit' => 'GB',
                        'is_verified' => true,
                        'is_filterable' => true,
                        'sort_order' => 1,
                    ],
                    $warranty->id => [
                        'value_number' => 24,
                        'unit' => 'months',
                        'sort_order' => 2,
                    ],
                    $color->id => [
                        'attribute_value_id' => null,
                        'sort_order' => 3,
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $ram->id,
            'value_text' => '16',
            'custom_value' => '16',
            'unit' => 'GB',
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_verified' => true,
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $warranty->id,
            'value_number' => '24.0000',
            'custom_value' => '24',
            'unit' => 'months',
            'source' => ProductAttributeValue::SOURCE_MANUAL,
        ]);
        $this->assertDatabaseMissing('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $color->id,
        ]);
        $this->assertSame(3, CategoryProductAttribute::query()->count());
        $this->assertSame($category->id, $product->fresh()->category_id);
    }

    public function test_required_category_attribute_is_visual_only_and_empty_save_does_not_create_value(): void
    {
        $this->actingAsSuperAdmin();

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $importantAttribute = ProductAttribute::factory()->create([
            'code' => 'processor',
            'type' => ProductAttribute::TYPE_TEXT,
        ]);

        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $importantAttribute->id,
            'is_required' => true,
        ]);

        $component = $this->relationManager($product);

        $this->assertTrue($component->instance()->categorySpecificationRowsForProduct($product)[0]['is_required']);

        $component
            ->callTableAction('saveCategorySpecifications', null, [
                'specifications' => [
                    $importantAttribute->id => [
                        'value_text' => '',
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_category_editor_clears_existing_value_without_removing_extra_values(): void
    {
        $this->actingAsSuperAdmin();

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $categoryAttribute = ProductAttribute::factory()->create([
            'code' => 'screen_size',
            'type' => ProductAttribute::TYPE_TEXT,
        ]);
        $extraAttribute = ProductAttribute::factory()->create([
            'code' => 'internal_note',
            'type' => ProductAttribute::TYPE_TEXT,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $categoryAttribute->id,
        ]);
        $categoryValue = ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $categoryAttribute->id,
            'value_text' => '15.6',
            'custom_value' => '15.6',
        ]);
        $extraValue = ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $extraAttribute->id,
            'value_text' => 'Admin-only note',
            'custom_value' => 'Admin-only note',
        ]);

        $this->relationManager($product)
            ->callTableAction('saveCategorySpecifications', null, [
                'specifications' => [
                    $categoryAttribute->id => [
                        'value_text' => '',
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('product_attribute_values', [
            'id' => $categoryValue->id,
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'id' => $extraValue->id,
            'product_id' => $product->id,
            'product_attribute_id' => $extraAttribute->id,
        ]);

        $outOfCategory = $this->relationManager($product)
            ->instance()
            ->outOfCategoryAttributeValuesForProduct($product);

        $this->assertCount(1, $outOfCategory);
        $this->assertSame($extraValue->id, $outOfCategory[0]->id);
    }

    public function test_category_editor_uses_existing_options_and_rejects_wrong_options(): void
    {
        $this->actingAsSuperAdmin();

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $color = ProductAttribute::factory()->create([
            'code' => 'color',
            'type' => ProductAttribute::TYPE_SELECT,
        ]);
        $panelType = ProductAttribute::factory()->create([
            'code' => 'panel_type',
            'type' => ProductAttribute::TYPE_SELECT,
        ]);
        $black = AttributeValue::factory()->create([
            'product_attribute_id' => $color->id,
            'value' => 'Black',
        ]);
        $wrongOption = AttributeValue::factory()->create([
            'product_attribute_id' => $panelType->id,
            'value' => 'IPS',
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $color->id,
        ]);

        $attributeCount = ProductAttribute::query()->count();
        $optionCount = AttributeValue::query()->count();

        $this->relationManager($product)
            ->callTableAction('saveCategorySpecifications', null, [
                'specifications' => [
                    $color->id => [
                        'attribute_value_id' => $wrongOption->id,
                    ],
                ],
            ])
            ->assertHasTableActionErrors();

        $this->relationManager($product)
            ->callTableAction('saveCategorySpecifications', null, [
                'specifications' => [
                    $color->id => [
                        'attribute_value_id' => $black->id,
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame($attributeCount, ProductAttribute::query()->count());
        $this->assertSame($optionCount, AttributeValue::query()->count());
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $color->id,
            'attribute_value_id' => $black->id,
            'custom_value' => 'Black',
        ]);
    }

    public function test_viewer_auditor_cannot_mutate_product_attribute_values(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER_AUDITOR,
            'is_active' => true,
        ]);

        $this->assertFalse(Gate::forUser($viewer)->allows('create', ProductAttributeValue::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', ProductAttributeValue::factory()->make()));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', ProductAttributeValue::factory()->make()));
    }

    public function test_visiting_product_admin_does_not_autofill_or_mutate_products_or_supplier_products(): void
    {
        $this->actingAsSuperAdmin();

        $product = Product::factory()->supplierPublished()->create([
            'name' => 'Existing Product',
            'sku' => 'ATTR-MANUAL-SAFE-001',
        ]);
        $supplier = Supplier::factory()->create();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'ATTR-MANUAL-STAGED-001',
            'name' => 'Existing Staged Product',
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['RAM' => '16 GB']],
            'payload_hash' => 'attr-manual-safe-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $productSnapshot = $product->fresh()->only(['name', 'sku', 'category_id', 'workflow_status', 'product_status', 'active', 'updated_at']);
        $supplierProductSnapshot = $supplierProduct->fresh()->only(['name', 'supplier_sku', 'raw_data', 'status', 'updated_at']);

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk();

        $this->assertSame(0, ProductAttributeValue::query()->count());
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
        $this->assertEquals($supplierProductSnapshot, $supplierProduct->fresh()->only(array_keys($supplierProductSnapshot)));
        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    private function relationManager(Product $product)
    {
        return Livewire::test(ProductAttributeValuesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ]);
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
