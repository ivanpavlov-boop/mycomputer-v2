<?php

namespace Tests\Feature;

use App\Enums\CategoryAttributeFilterControl;
use App\Filament\Resources\CategoryProductAttributes\Pages\CreateCategoryProductAttribute;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Products\PublicProductPriceFilterService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ConfigurableStorefrontFilterControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_domain_defaults_labels_and_compatibility_are_centralized(): void
    {
        $this->assertSame([
            'auto' => 'Автоматично',
            'options' => 'Избор от стойности',
            'yes_no' => 'Да / Не',
            'range_slider' => 'Плъзгач',
            'min_max' => 'Начална и крайна стойност',
        ], CategoryAttributeFilterControl::options());
        $this->assertSame(CategoryAttributeFilterControl::Options, CategoryAttributeFilterControl::Auto->resolveForAttributeType(ProductAttribute::TYPE_SELECT));
        $this->assertSame(CategoryAttributeFilterControl::YesNo, CategoryAttributeFilterControl::Auto->resolveForAttributeType(ProductAttribute::TYPE_BOOLEAN));
        $this->assertSame(CategoryAttributeFilterControl::MinMax, CategoryAttributeFilterControl::Auto->resolveForAttributeType(ProductAttribute::TYPE_DECIMAL));
        $this->assertNull(CategoryAttributeFilterControl::Auto->resolveForAttributeType(ProductAttribute::TYPE_TEXT));
        $this->assertSame(CategoryAttributeFilterControl::Auto, CategoryAttributeFilterControl::fromPersisted('legacy-unknown'));
    }

    public function test_additive_column_defaults_existing_assignments_to_auto_and_rolls_back_only_it(): void
    {
        $category = Category::factory()->create();
        $attribute = $this->attribute('legacy', ProductAttribute::TYPE_SELECT);
        $assignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
        ]);

        $this->assertSame('auto', $assignment->fresh()->filter_control_type);

        $migration = require database_path('migrations/2026_07_22_090000_add_filter_control_type_to_category_product_attributes.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('category_product_attributes', 'filter_control_type'));
        $this->assertTrue(Schema::hasColumn('category_product_attributes', 'is_filterable'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('category_product_attributes', 'filter_control_type'));
        $this->assertSame('auto', DB::table('category_product_attributes')->value('filter_control_type'));
    }

    public function test_model_rejects_incompatible_controls_and_safely_reads_unknown_legacy_values(): void
    {
        $category = Category::factory()->create();
        $select = $this->attribute('panel', ProductAttribute::TYPE_SELECT);

        try {
            CategoryProductAttribute::factory()->create([
                'category_id' => $category->id,
                'product_attribute_id' => $select->id,
                'filter_control_type' => CategoryAttributeFilterControl::RangeSlider->value,
            ]);
            $this->fail('An incompatible filter control was persisted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('filter_control_type', $exception->errors());
        }

        $assignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $select->id,
            'filter_control_type' => CategoryAttributeFilterControl::Options->value,
        ]);
        $assignment->update(['is_filterable' => false]);
        $this->assertSame(CategoryAttributeFilterControl::Options->value, $assignment->fresh()->filter_control_type);
        $assignment->update(['is_filterable' => true]);
        DB::table('category_product_attributes')->where('id', $assignment->id)->update(['filter_control_type' => 'legacy-unknown']);

        $this->assertSame(CategoryAttributeFilterControl::Auto, $assignment->fresh()->configuredFilterControl());
        $this->assertSame(CategoryAttributeFilterControl::Options, $assignment->fresh()->resolvedFilterControl());
    }

    public function test_filament_assignment_form_saves_valid_control_and_rejects_invalid_control(): void
    {
        $this->actingAsSuperAdmin();
        $select = $this->attribute('screen', ProductAttribute::TYPE_SELECT);
        $number = $this->attribute('weight', ProductAttribute::TYPE_DECIMAL);
        $firstCategory = Category::factory()->create();
        $secondCategory = Category::factory()->create();

        Livewire::test(CreateCategoryProductAttribute::class)
            ->fillForm([
                'category_id' => $firstCategory->id,
                'product_attribute_id' => $number->id,
                'is_filterable' => true,
                'filter_control_type' => CategoryAttributeFilterControl::RangeSlider->value,
                'is_visible_on_product' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('category_product_attributes', [
            'category_id' => $firstCategory->id,
            'filter_control_type' => 'range_slider',
        ]);

        Livewire::test(CreateCategoryProductAttribute::class)
            ->fillForm([
                'category_id' => $secondCategory->id,
                'product_attribute_id' => $select->id,
                'is_filterable' => true,
                'filter_control_type' => CategoryAttributeFilterControl::RangeSlider->value,
                'is_visible_on_product' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['filter_control_type']);
    }

    public function test_category_controls_inherit_override_and_disable_through_existing_resolver(): void
    {
        $parent = Category::factory()->create();
        $inherited = Category::factory()->create(['parent_id' => $parent->id]);
        $overridden = Category::factory()->create(['parent_id' => $parent->id]);
        $disabled = Category::factory()->create(['parent_id' => $parent->id]);
        $weight = $this->attribute('weight', ProductAttribute::TYPE_DECIMAL);
        $this->assign($parent, $weight, CategoryAttributeFilterControl::RangeSlider);
        $this->assign($overridden, $weight, CategoryAttributeFilterControl::MinMax);
        $this->assign($disabled, $weight, CategoryAttributeFilterControl::RangeSlider, false);

        foreach ([$inherited, $overridden, $disabled] as $category) {
            $this->numericProducts($category, $weight, [1, 2]);
        }

        $this->getJson("/api/v1/categories/{$inherited->slug}/products")
            ->assertOk()
            ->assertJsonPath('filters.0.type', 'number_range')
            ->assertJsonPath('filters.0.control', 'range_slider');
        $this->getJson("/api/v1/categories/{$overridden->slug}/products")
            ->assertOk()
            ->assertJsonPath('filters.0.control', 'min_max');
        $this->getJson("/api/v1/categories/{$disabled->slug}/products")
            ->assertOk()
            ->assertJsonPath('filters', []);
    }

    public function test_options_and_yes_no_controls_are_exposed_for_direct_and_inherited_categories(): void
    {
        $direct = Category::factory()->create();
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $booleanCategory = Category::factory()->create();
        $ram = $this->attribute('ram', ProductAttribute::TYPE_SELECT);
        $wifi = $this->attribute('wifi', ProductAttribute::TYPE_BOOLEAN);
        [$ram16, $ram32] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        $this->assign($direct, $ram, CategoryAttributeFilterControl::Options);
        $this->assign($parent, $ram, CategoryAttributeFilterControl::Options);
        $this->assign($booleanCategory, $wifi, CategoryAttributeFilterControl::YesNo);
        $this->value($this->product($direct), $ram, ['attribute_value_id' => $ram16->id]);
        $this->value($this->product($direct), $ram, ['attribute_value_id' => $ram32->id]);
        $this->value($this->product($child), $ram, ['attribute_value_id' => $ram16->id]);
        $this->value($this->product($child), $ram, ['attribute_value_id' => $ram32->id]);
        $this->value($this->product($booleanCategory, ['slug' => 'wifi-yes']), $wifi, ['value_boolean' => true]);
        $this->value($this->product($booleanCategory, ['slug' => 'wifi-no']), $wifi, ['value_boolean' => false]);

        foreach ([$direct, $child] as $category) {
            $this->getJson("/api/v1/categories/{$category->slug}/products")
                ->assertOk()
                ->assertJsonPath('filters.0.type', 'select')
                ->assertJsonPath('filters.0.control', 'options');
        }

        $this->getJson("/api/v1/categories/{$booleanCategory->slug}/products")
            ->assertOk()
            ->assertJsonPath('filters.0.type', 'boolean')
            ->assertJsonPath('filters.0.control', 'yes_no')
            ->assertJsonPath('filters.0.options.0', ['key' => 'yes', 'label' => 'Да'])
            ->assertJsonPath('filters.0.options.1', ['key' => 'no', 'label' => 'Не']);

        $query = http_build_query(['attribute_filters' => ['wifi' => ['yes']]]);
        $this->getJson("/api/v1/categories/{$booleanCategory->slug}/products?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'wifi-yes');
    }

    public function test_same_numeric_attribute_can_use_different_category_controls_with_safe_catalog_fallback(): void
    {
        $sliderCategory = Category::factory()->create();
        $fieldsCategory = Category::factory()->create();
        $weight = $this->attribute('weight', ProductAttribute::TYPE_DECIMAL);
        $this->assign($sliderCategory, $weight, CategoryAttributeFilterControl::RangeSlider);
        $this->assign($fieldsCategory, $weight, CategoryAttributeFilterControl::MinMax);
        $this->numericProducts($sliderCategory, $weight, [1.1, 2.2]);
        $this->numericProducts($fieldsCategory, $weight, [2.5, 3.5]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('filters.0.type', 'number_range')
            ->assertJsonPath('filters.0.control', 'min_max')
            ->assertJsonMissingPath('filters.0.category_assignment_id')
            ->assertJsonMissingPath('filters.0.template_source');
    }

    public function test_broad_catalog_omits_corrupt_incompatible_control_without_rewriting_it(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();
        $weight = $this->attribute('weight', ProductAttribute::TYPE_DECIMAL);
        $this->assign($first, $weight, CategoryAttributeFilterControl::RangeSlider);
        $corrupt = $this->assign($second, $weight, CategoryAttributeFilterControl::MinMax);
        DB::table('category_product_attributes')->where('id', $corrupt->id)->update(['filter_control_type' => 'yes_no']);
        $this->numericProducts($first, $weight, [1, 2]);
        $this->numericProducts($second, $weight, [3, 4]);

        $this->getJson("/api/v1/categories/{$second->slug}/products")->assertOk()->assertJsonPath('filters', []);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('filters', []);
        $this->assertSame('yes_no', DB::table('category_product_attributes')->where('id', $corrupt->id)->value('filter_control_type'));
    }

    public function test_price_metadata_uses_exact_public_scope_and_excludes_only_selected_price_bounds(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $otherBrand = Brand::factory()->create();
        $low = $this->product($category, ['brand_id' => $brand->id, 'price' => 100, 'name' => 'Scoped laptop']);
        $high = $this->product($category, ['brand_id' => $brand->id, 'price' => 300, 'name' => 'Scoped laptop pro']);
        $this->product($category, ['brand_id' => $otherBrand->id, 'price' => 900]);
        $this->product($category, ['brand_id' => $brand->id, 'price' => 10, 'active' => false]);
        $deleted = $this->product($category, ['brand_id' => $brand->id, 'price' => 20]);
        $deleted->delete();

        $query = http_build_query([
            'brand' => $brand->slug,
            'search' => 'Scoped',
            'price_min' => 150,
            'price_max' => 350,
        ]);

        $this->getJson('/api/v1/products?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $high->id)
            ->assertJsonPath('price_filter.control', 'range_slider')
            ->assertJsonPath('price_filter.currency', 'EUR')
            ->assertJsonPath('price_filter.min', 100)
            ->assertJsonPath('price_filter.max', 300)
            ->assertJsonPath('price_filter.selected_min', 150)
            ->assertJsonPath('price_filter.selected_max', 350)
            ->assertJsonMissingPath('price_filter.supplier_cost');

        $this->assertTrue($low->exists);
    }

    public function test_category_and_brand_scopes_constrain_price_metadata_while_attributes_do_not_and_equal_bounds_hide_it(): void
    {
        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $brand = Brand::factory()->create();
        $ram = $this->attribute('ram', ProductAttribute::TYPE_SELECT);
        [$sixteen, $thirtyTwo] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        $this->assign($category, $ram, CategoryAttributeFilterControl::Options);
        $first = $this->product($category, ['brand_id' => $brand->id, 'price' => 100]);
        $second = $this->product($category, ['brand_id' => $brand->id, 'price' => 200]);
        $otherOption = $this->product($category, ['brand_id' => $brand->id, 'price' => 500]);
        $this->product($otherCategory, ['brand_id' => $brand->id, 'price' => 900]);
        $this->value($first, $ram, ['attribute_value_id' => $sixteen->id]);
        $this->value($second, $ram, ['attribute_value_id' => $sixteen->id]);
        $this->value($otherOption, $ram, ['attribute_value_id' => $thirtyTwo->id]);
        $attributes = http_build_query(['attribute_filters' => ['ram' => ['16-gb']]]);

        $this->getJson("/api/v1/categories/{$category->slug}/products?{$attributes}")
            ->assertOk()
            ->assertJsonPath('price_filter.min', 100)
            ->assertJsonPath('price_filter.max', 500);
        $this->getJson("/api/v1/brands/{$brand->slug}/products?{$attributes}")
            ->assertOk()
            ->assertJsonPath('price_filter.min', 100)
            ->assertJsonPath('price_filter.max', 900);

        $single = Category::factory()->create();
        $this->product($single, ['price' => 42]);
        $this->getJson("/api/v1/categories/{$single->slug}/products")
            ->assertOk()
            ->assertJsonPath('price_filter', null);
    }

    public function test_non_numeric_or_negative_price_rows_cannot_expand_public_bounds(): void
    {
        $category = Category::factory()->create();
        $this->product($category, ['price' => 50]);
        $this->product($category, ['price' => 150]);
        $invalid = $this->product($category, ['price' => 75]);

        if (DB::getDriverName() === 'sqlite') {
            DB::table('products')->where('id', $invalid->id)->update(['price' => 'not-a-price']);
        } else {
            DB::table('products')->where('id', $invalid->id)->update(['price' => -1]);
        }

        $metadata = app(PublicProductPriceFilterService::class)->describe(Product::query()->published());

        $this->assertSame(50.0, $metadata['price_filter']['min']);
        $this->assertSame(150.0, $metadata['price_filter']['max']);
    }

    public function test_filter_metadata_requests_are_read_only_and_sync_safety_stays_locked(): void
    {
        $category = Category::factory()->create();
        $this->product($category, ['price' => 100]);
        $this->product($category, ['price' => 200]);
        $tables = ['products', 'categories', 'product_attributes', 'attribute_values', 'product_attribute_values', 'category_product_attributes', 'supplier_products'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $timestamps = Product::query()->pluck('updated_at', 'id')->map(fn (mixed $value): string => (string) $value)->all();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/products')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $after = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $this->assertSame($before, $after);
        $this->assertSame($timestamps, Product::query()->pluck('updated_at', 'id')->map(fn (mixed $value): string => (string) $value)->all());
        $this->assertLessThanOrEqual(28, $queryCount);
        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function attribute(string $code, string $type): ProductAttribute
    {
        return ProductAttribute::factory()->create([
            'attribute_group_id' => AttributeGroup::factory()->create(['is_active' => true])->id,
            'code' => $code,
            'slug' => str_replace('_', '-', $code),
            'name' => $code,
            'name_bg' => $code,
            'type' => $type,
            'is_filterable' => true,
            'is_visible_on_product' => true,
            'is_active' => true,
        ]);
    }

    private function assign(Category $category, ProductAttribute $attribute, CategoryAttributeFilterControl $control, bool $filterable = true): CategoryProductAttribute
    {
        return CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_filterable' => $filterable,
            'is_visible_on_product' => true,
            'filter_control_type' => $control->value,
        ]);
    }

    private function product(Category $category, array $attributes = []): Product
    {
        return Product::factory()->create(['category_id' => $category->id, ...$attributes]);
    }

    private function numericProducts(Category $category, ProductAttribute $attribute, array $numbers): void
    {
        foreach ($numbers as $number) {
            $this->value($this->product($category), $attribute, ['value_number' => $number]);
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array<int, AttributeValue>
     */
    private function attributeOptions(ProductAttribute $attribute, array $values): array
    {
        return collect($values)->map(fn (string $slug, string $label): AttributeValue => AttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'value' => $label,
            'slug' => $slug,
            'is_active' => true,
        ]))->values()->all();
    }

    private function value(Product $product, ProductAttribute $attribute, array $values): ProductAttributeValue
    {
        return ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => null,
            'value_number' => null,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_filterable' => true,
            ...$values,
        ]);
    }
}
