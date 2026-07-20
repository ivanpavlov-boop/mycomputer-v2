<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
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
use App\Services\Products\ProductSpecificationQualityResult;
use App\Services\Products\ProductSpecificationQualityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class ProductSpecificationQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_with_all_important_category_specs_filled_is_good(): void
    {
        [$product, $ram, $processor] = $this->productWithRequiredAttributes(['RAM', 'Processor']);

        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $ram->id,
            'value_text' => '16 GB',
            'custom_value' => '16 GB',
        ]);
        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $processor->id,
            'value_text' => 'Intel Core i7',
            'custom_value' => 'Intel Core i7',
        ]);

        $result = app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_GOOD, $result->status);
        $this->assertSame('Добро', $result->statusLabel());
        $this->assertSame(2, $result->expectedCount);
        $this->assertSame(2, $result->filledCount);
        $this->assertSame(0, $result->missingCount);
        $this->assertSame(100, $result->percentageComplete);
    }

    public function test_missing_important_specs_return_missing_list(): void
    {
        [$product, $ram, $processor] = $this->productWithRequiredAttributes(['RAM', 'Processor']);

        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $ram->id,
            'value_text' => '32 GB',
            'custom_value' => '32 GB',
        ]);

        $result = app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, $result->status);
        $this->assertSame(1, $result->filledCount);
        $this->assertSame(1, $result->missingCount);
        $this->assertSame(['Processor'], $result->missingAttributeLabels());
        $this->assertSame($processor->id, $result->missingAttributes->first()['attribute']->id);
    }

    public function test_product_without_category_template_returns_no_template_status(): void
    {
        $product = Product::factory()->create();

        $result = app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE, $result->status);
        $this->assertSame('Няма зададен шаблон за категория', $result->statusLabel());
        $this->assertSame(0, $result->expectedCount);
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_visible_filterable_or_comparable_category_specs_are_recommended_when_required_flags_are_absent(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $visible = $this->attribute('Screen size', ProductAttribute::TYPE_TEXT);
        $hidden = ProductAttribute::factory()->create([
            'code' => 'internal_note_for_quality',
            'name' => 'Internal note',
            'name_bg' => 'Internal note',
            'type' => ProductAttribute::TYPE_TEXT,
            'is_filterable' => false,
            'is_visible_on_product' => false,
            'is_comparable' => false,
            'is_active' => true,
        ]);

        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $visible->id,
            'is_required' => false,
            'is_visible_on_product' => true,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $hidden->id,
            'is_required' => false,
            'is_filterable' => false,
            'is_visible_on_product' => false,
            'is_comparable' => false,
        ]);

        $result = app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_NEEDS_DATA, $result->status);
        $this->assertSame(1, $result->expectedCount);
        $this->assertSame(['Screen size'], $result->missingAttributeLabels());
    }

    public function test_empty_values_are_missing_boolean_false_is_filled_and_invalid_select_is_missing(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $text = $this->attribute('RAM', ProductAttribute::TYPE_TEXT);
        $boolean = $this->attribute('Has webcam', ProductAttribute::TYPE_BOOLEAN);
        $select = $this->attribute('Color', ProductAttribute::TYPE_SELECT);
        $otherSelect = $this->attribute('Panel type', ProductAttribute::TYPE_SELECT);
        $wrongOption = AttributeValue::factory()->create([
            'product_attribute_id' => $otherSelect->id,
            'value' => 'IPS',
        ]);

        foreach ([$text, $boolean, $select] as $index => $attribute) {
            CategoryProductAttribute::factory()->create([
                'category_id' => $category->id,
                'product_attribute_id' => $attribute->id,
                'is_required' => true,
                'sort_order' => $index + 1,
            ]);
        }

        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $text->id,
            'value_text' => '',
            'custom_value' => '',
        ]);
        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $boolean->id,
            'value_text' => null,
            'value_boolean' => false,
            'custom_value' => 'false',
        ]);
        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $select->id,
            'attribute_value_id' => $wrongOption->id,
            'value_text' => null,
            'custom_value' => 'IPS',
        ]);

        $result = app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, $result->status);
        $this->assertSame(1, $result->filledCount);
        $this->assertSame(2, $result->missingCount);
        $this->assertSame(['RAM', 'Color'], $result->missingAttributeLabels());
        $this->assertSame(['Has webcam'], $result->filledAttributes->pluck('label')->all());
    }

    public function test_multiple_categories_merge_and_child_category_assignment_takes_precedence(): void
    {
        $parentCategory = Category::factory()->create(['slug' => 'computers']);
        $childCategory = Category::factory()->create([
            'parent_id' => $parentCategory->id,
            'slug' => 'laptops',
        ]);
        $product = Product::factory()->create(['category_id' => $childCategory->id]);
        $shared = $this->attribute('RAM', ProductAttribute::TYPE_TEXT);
        $parentOnly = $this->attribute('Warranty', ProductAttribute::TYPE_NUMBER);

        CategoryProductAttribute::factory()->create([
            'category_id' => $parentCategory->id,
            'product_attribute_id' => $shared->id,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $parentCategory->id,
            'product_attribute_id' => $parentOnly->id,
            'is_required' => true,
            'sort_order' => 2,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $childCategory->id,
            'product_attribute_id' => $shared->id,
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $result = app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame(2, $result->expectedCount);
        $this->assertSame([$shared->id, $parentOnly->id], $result->expectedAttributes->pluck('attribute.id')->all());
        $this->assertSame($childCategory->id, $result->expectedAttributes->first()['assignment']->category_id);
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_out_of_category_attribute_value_does_not_satisfy_required_category_spec(): void
    {
        [$product, $ram] = $this->productWithRequiredAttributes(['RAM']);
        $color = $this->attribute('Color', ProductAttribute::TYPE_TEXT);

        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $color->id,
            'value_text' => 'Black',
            'custom_value' => 'Black',
        ]);

        $result = app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, $result->status);
        $this->assertSame([$ram->id], $result->missingAttributes->pluck('attribute.id')->all());
    }

    public function test_quality_calculation_and_audit_command_are_read_only(): void
    {
        [$product] = $this->productWithRequiredAttributes(['RAM']);
        $supplier = Supplier::factory()->create();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SPEC-AUDIT-STAGED-001',
            'name' => 'Staged supplier product',
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['RAM' => '16 GB']],
            'payload_hash' => 'spec-audit-staged-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $before = $this->databaseCounts();
        $productSnapshot = $product->fresh()->only(['name', 'sku', 'category_id', 'workflow_status', 'product_status', 'active', 'updated_at']);

        app(ProductSpecificationQualityService::class)->evaluate($product);

        $this->assertSame($before, $this->databaseCounts());
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));

        $this->assertSame(0, Artisan::call('products:audit-specification-quality'));
        $output = Artisan::output();

        $this->assertStringContainsString('Product specification quality audit', $output);
        $this->assertStringContainsString('Products with missing important specs: 1', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('product_attribute_values changed: 0', $output);
        $this->assertStringContainsString('category_product_attributes changed: 0', $output);
        $this->assertFalse(Artisan::all()['products:audit-specification-quality']->getDefinition()->hasOption('apply'));

        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_product_admin_keeps_specification_quality_available_and_shows_warning_panel(): void
    {
        $this->actingAsSuperAdmin();

        [$product] = $this->productWithRequiredAttributes(['RAM']);

        $table = Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableColumnStateSet('specification_quality', 'Липсват важни характеристики', $product)
            ->assertTableColumnHasDescription('specification_quality', '0/1 (0%)', $product)
            ->instance()
            ->getTable();

        $qualityColumn = $table->getColumn('specification_quality');

        $this->assertTrue($qualityColumn->isToggleable());
        $this->assertTrue($qualityColumn->isToggledHiddenByDefault());

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('Характеристики');

        Livewire::test(ProductAttributeValuesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->assertSee('Качество на характеристиките')
            ->assertSee('Липсват: RAM')
            ->assertSee('не блокира записа');
    }

    public function test_missing_specs_do_not_block_manual_category_spec_save_or_create_rows(): void
    {
        $this->actingAsSuperAdmin();

        [$product, $ram] = $this->productWithRequiredAttributes(['RAM']);

        Livewire::test(ProductAttributeValuesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('saveCategorySpecifications', null, [
                'specifications' => [
                    $ram->id => [
                        'value_text' => '',
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, ProductAttributeValue::query()->count());
        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, app(ProductSpecificationQualityService::class)->evaluate($product)->status);
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, mixed>
     */
    private function productWithRequiredAttributes(array $labels): array
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $attributes = [];

        foreach ($labels as $index => $label) {
            $attribute = $this->attribute($label, ProductAttribute::TYPE_TEXT);
            $attributes[] = $attribute;

            CategoryProductAttribute::factory()->create([
                'category_id' => $category->id,
                'product_attribute_id' => $attribute->id,
                'is_required' => true,
                'sort_order' => $index + 1,
            ]);
        }

        return [$product, ...$attributes];
    }

    private function attribute(string $label, string $type): ProductAttribute
    {
        return ProductAttribute::factory()->create([
            'code' => strtolower(str_replace(' ', '_', $label)).'_'.fake()->unique()->numberBetween(1000, 9999),
            'name' => $label,
            'name_bg' => $label,
            'type' => $type,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function databaseCounts(): array
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

    private function actingAsSuperAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
