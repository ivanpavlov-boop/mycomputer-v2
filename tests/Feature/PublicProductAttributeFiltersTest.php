<?php

namespace Tests\Feature;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicProductAttributeFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_and_inherited_templates_expose_only_useful_catalog_filters(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $ram = $this->attribute('ram', 'Оперативна памет', ProductAttribute::TYPE_SELECT);
        [$sixteen, $thirtyTwo] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        $this->assign($parent, $ram);
        $first = $this->product($child, ['slug' => 'ram-16']);
        $second = $this->product($child, ['slug' => 'ram-32']);
        $this->value($first, $ram, ['attribute_value_id' => $sixteen->id]);
        $this->value($second, $ram, ['attribute_value_id' => $thirtyTwo->id]);

        $response = $this->getJson('/api/v1/categories/'.$child->slug.'/products');

        $response
            ->assertOk()
            ->assertJsonPath('filters.0.key', 'ram')
            ->assertJsonPath('filters.0.label', 'Оперативна памет')
            ->assertJsonPath('filters.0.type', ProductAttribute::TYPE_SELECT)
            ->assertJsonCount(2, 'filters.0.options')
            ->assertJsonMissingPath('filters.0.id')
            ->assertJsonMissingPath('filters.0.options.0.id');
    }

    public function test_closest_category_assignment_precedence_and_category_scope_are_preserved(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $sibling = Category::factory()->create(['parent_id' => $parent->id]);
        $panel = $this->attribute('panel', 'Панел', ProductAttribute::TYPE_SELECT);
        [$ips, $oled] = $this->attributeOptions($panel, ['IPS' => 'ips', 'OLED' => 'oled']);
        $this->assign($parent, $panel);
        $this->assign($child, $panel, filterable: false);
        $childProduct = $this->product($child, ['slug' => 'child-panel']);
        $siblingProduct = $this->product($sibling, ['slug' => 'sibling-panel']);
        $this->value($childProduct, $panel, ['attribute_value_id' => $ips->id]);
        $this->value($siblingProduct, $panel, ['attribute_value_id' => $oled->id]);

        $this->getJson('/api/v1/categories/'.$child->slug.'/products')
            ->assertOk()
            ->assertJsonPath('filters', [])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'child-panel');
    }

    public function test_select_values_use_or_semantics_and_different_attributes_use_and_semantics(): void
    {
        $category = Category::factory()->create();
        $ram = $this->attribute('ram', 'RAM', ProductAttribute::TYPE_SELECT, position: 10);
        $storage = $this->attribute('storage', 'Диск', ProductAttribute::TYPE_SELECT, position: 20);
        [$ram16, $ram32] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        [$ssd512, $ssd1tb] = $this->attributeOptions($storage, ['512 GB' => '512-gb', '1 TB' => '1-tb']);
        $this->assign($category, $ram, 10);
        $this->assign($category, $storage, 20);
        $first = $this->product($category, ['slug' => 'first']);
        $second = $this->product($category, ['slug' => 'second']);
        $third = $this->product($category, ['slug' => 'third']);
        $this->value($first, $ram, ['attribute_value_id' => $ram16->id]);
        $this->value($first, $storage, ['attribute_value_id' => $ssd512->id]);
        $this->value($second, $ram, ['attribute_value_id' => $ram32->id]);
        $this->value($second, $storage, ['attribute_value_id' => $ssd512->id]);
        $this->value($third, $ram, ['attribute_value_id' => $ram16->id]);
        $this->value($third, $storage, ['attribute_value_id' => $ssd1tb->id]);

        $response = $this->filtered([
            'ram' => ['16-gb', '32-gb'],
            'storage' => ['512-gb'],
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['slug' => 'first'])
            ->assertJsonFragment(['slug' => 'second'])
            ->assertJsonMissing(['slug' => 'third'])
            ->assertJsonPath('active_filters.0.key', 'ram')
            ->assertJsonPath('active_filters.1.key', 'storage');
    }

    public function test_multiselect_filter_uses_exact_option_ids_and_ignores_malformed_values(): void
    {
        $category = Category::factory()->create();
        $ports = $this->attribute('ports', 'Портове', ProductAttribute::TYPE_MULTISELECT);
        [$usb, $hdmi] = $this->attributeOptions($ports, ['USB-C' => 'usb-c', 'HDMI' => 'hdmi']);
        $foreignAttribute = ProductAttribute::factory()->create(['type' => ProductAttribute::TYPE_SELECT]);
        $foreign = AttributeValue::factory()->create(['product_attribute_id' => $foreignAttribute->id]);
        $this->assign($category, $ports);
        $valid = $this->product($category, ['slug' => 'valid-multi']);
        $other = $this->product($category, ['slug' => 'other-multi']);
        $malformed = $this->product($category, ['slug' => 'malformed-multi']);
        $this->value($valid, $ports, ['value_json' => ['attribute_value_ids' => [$usb->id]]]);
        $this->value($other, $ports, ['value_json' => ['attribute_value_ids' => [$hdmi->id]]]);
        $this->value($malformed, $ports, ['value_json' => ['attribute_value_ids' => [$usb->id, $foreign->id]]]);

        $this->filtered(['ports' => ['usb-c']])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'valid-multi');
    }

    public function test_boolean_yes_and_no_filters_work_with_customer_labels(): void
    {
        $category = Category::factory()->create();
        $wifi = $this->attribute('wifi', 'Безжична мрежа', ProductAttribute::TYPE_BOOLEAN);
        $this->assign($category, $wifi);
        $yes = $this->product($category, ['slug' => 'wifi-yes']);
        $no = $this->product($category, ['slug' => 'wifi-no']);
        $this->value($yes, $wifi, ['value_boolean' => true]);
        $this->value($no, $wifi, ['value_boolean' => false]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('filters.0.options.0', ['key' => 'yes', 'label' => 'Да'])
            ->assertJsonPath('filters.0.options.1', ['key' => 'no', 'label' => 'Не']);
        $this->filtered(['wifi' => ['yes']])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'wifi-yes');
        $this->filtered(['wifi' => ['no']])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'wifi-no');
    }

    public function test_numeric_ranges_are_inclusive_and_reject_inverted_ranges(): void
    {
        $category = Category::factory()->create();
        $weight = $this->attribute('weight', 'Тегло', ProductAttribute::TYPE_DECIMAL, unit: 'kg');
        $this->assign($category, $weight);
        foreach ([1.0 => 'light', 2.0 => 'middle', 3.0 => 'heavy'] as $number => $slug) {
            $product = $this->product($category, ['slug' => $slug]);
            $this->value($product, $weight, ['value_number' => $number]);
        }

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('filters.0.type', 'number_range')
            ->assertJsonPath('filters.0.min', 1)
            ->assertJsonPath('filters.0.max', 3)
            ->assertJsonPath('filters.0.unit', 'kg');
        $this->filtered(['weight' => ['min' => '1', 'max' => '2']])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['slug' => 'light'])
            ->assertJsonFragment(['slug' => 'middle']);
        $this->filtered(['weight' => ['min' => '3', 'max' => '2']])
            ->assertUnprocessable()
            ->assertJsonMissingPath('exception');
    }

    public function test_unknown_attributes_options_and_operators_are_rejected_safely(): void
    {
        $category = Category::factory()->create();
        $ram = $this->attribute('ram', 'RAM', ProductAttribute::TYPE_SELECT);
        [$sixteen, $thirtyTwo] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        $this->assign($category, $ram);
        $this->value($this->product($category), $ram, ['attribute_value_id' => $sixteen->id]);
        $this->value($this->product($category), $ram, ['attribute_value_id' => $thirtyTwo->id]);

        $this->filtered(['unknown' => ['value']])->assertUnprocessable();
        $this->filtered(['ram' => ['64-gb']])->assertUnprocessable();

        $weight = $this->attribute('weight', 'Тегло', ProductAttribute::TYPE_NUMBER);
        $this->assign($category, $weight);
        $this->value($this->product($category), $weight, ['value_number' => 1]);
        $this->value($this->product($category), $weight, ['value_number' => 2]);
        $this->filtered(['weight' => ['greater_than' => '1']])->assertUnprocessable();
    }

    public function test_unpublished_hidden_soft_deleted_out_of_template_and_supplier_values_do_not_contribute(): void
    {
        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $ram = $this->attribute('ram', 'RAM', ProductAttribute::TYPE_SELECT);
        [$publicOption, $privateOption] = $this->attributeOptions($ram, ['16 GB' => '16-gb', 'Secret' => 'secret']);
        $this->assign($category, $ram);
        $public = $this->product($category);
        $this->value($public, $ram, ['attribute_value_id' => $publicOption->id]);
        $draft = Product::factory()->manualDraft()->create(['category_id' => $category->id]);
        $this->value($draft, $ram, ['attribute_value_id' => $privateOption->id]);
        $hidden = $this->product($category, ['active' => false]);
        $this->value($hidden, $ram, ['attribute_value_id' => $privateOption->id]);
        $deleted = $this->product($category);
        $this->value($deleted, $ram, ['attribute_value_id' => $privateOption->id]);
        $deleted->delete();
        $outOfTemplate = $this->product($otherCategory);
        $this->value($outOfTemplate, $ram, ['attribute_value_id' => $privateOption->id]);
        $supplierDerived = $this->product($category);
        $this->value($supplierDerived, $ram, [
            'attribute_value_id' => $privateOption->id,
            'source' => ProductAttributeValue::SOURCE_CONTROLLED_SYNC,
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('filters', [])
            ->assertJsonMissing(['key' => 'secret'])
            ->assertJsonMissingPath('supplier_products')
            ->assertJsonMissingPath('quality_status');
    }

    public function test_unsupported_and_useless_filters_are_hidden(): void
    {
        $category = Category::factory()->create();
        $text = $this->attribute('notes', 'Бележки', ProductAttribute::TYPE_TEXT);
        $single = $this->attribute('single', 'Една стойност', ProductAttribute::TYPE_SELECT);
        $equal = $this->attribute('equal', 'Еднакво число', ProductAttribute::TYPE_NUMBER);
        [$only] = $this->attributeOptions($single, ['Само' => 'only']);
        $this->assign($category, $text);
        $this->assign($category, $single);
        $this->assign($category, $equal);
        $first = $this->product($category);
        $second = $this->product($category);
        $this->value($first, $text, ['value_text' => 'free text']);
        $this->value($first, $single, ['attribute_value_id' => $only->id]);
        $this->value($second, $single, ['attribute_value_id' => $only->id]);
        $this->value($first, $equal, ['value_number' => 5]);
        $this->value($second, $equal, ['value_number' => 5]);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('filters', []);
    }

    public function test_attribute_filters_combine_with_brand_price_availability_search_sort_and_pagination(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create(['slug' => 'acme']);
        $otherBrand = Brand::factory()->create(['slug' => 'other']);
        $ram = $this->attribute('ram', 'RAM', ProductAttribute::TYPE_SELECT);
        [$sixteen, $thirtyTwo] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        $this->assign($category, $ram);
        $matching = $this->product($category, [
            'brand_id' => $brand->id,
            'name' => 'Acme Notebook',
            'price' => 100,
            'stock_status' => 'in_stock',
            'published_at' => now()->subDay(),
        ]);
        $other = $this->product($category, [
            'brand_id' => $otherBrand->id,
            'name' => 'Other Notebook',
            'price' => 200,
            'published_at' => now(),
        ]);
        $this->value($matching, $ram, ['attribute_value_id' => $sixteen->id]);
        $this->value($other, $ram, ['attribute_value_id' => $thirtyTwo->id]);
        $query = http_build_query([
            'brand' => 'acme',
            'price_min' => 90,
            'price_max' => 110,
            'stock_status' => 'in_stock',
            'search' => 'Acme',
            'sort' => 'price_desc',
            'per_page' => 1,
            'attribute_filters' => ['ram' => ['16-gb']],
        ]);

        $response = $this->getJson('/api/v1/products?'.$query);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $matching->slug)
            ->assertJsonPath('meta.per_page', 1);
        $this->assertStringContainsString('attribute_filters', urldecode((string) $response->json('links.first')));
    }

    public function test_localized_labels_are_json_escaped_and_internal_metadata_is_not_exposed(): void
    {
        $category = Category::factory()->create();
        $attribute = $this->attribute('unsafe', '<script>alert("x")</script>', ProductAttribute::TYPE_SELECT);
        [$first, $second] = $this->attributeOptions($attribute, [
            '<b>Първа</b>' => 'first',
            'A & B' => 'second',
        ]);
        $this->assign($category, $attribute);
        $this->value($this->product($category), $attribute, ['attribute_value_id' => $first->id]);
        $this->value($this->product($category), $attribute, ['attribute_value_id' => $second->id]);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $response
            ->assertJsonPath('filters.0.label', '<script>alert("x")</script>')
            ->assertJsonFragment(['key' => 'first', 'label' => '<b>Първа</b>'])
            ->assertJsonMissingPath('filters.0.required')
            ->assertJsonMissingPath('filters.0.template_source')
            ->assertJsonMissingPath('filters.0.supplier_id');
    }

    public function test_filter_queries_are_bounded_and_do_not_mutate_catalog_or_staging_tables(): void
    {
        $category = Category::factory()->create();
        $ram = $this->attribute('ram', 'RAM', ProductAttribute::TYPE_SELECT);
        [$sixteen, $thirtyTwo] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        $this->assign($category, $ram);
        $first = $this->product($category);
        $second = $this->product($category);
        $this->value($first, $ram, ['attribute_value_id' => $sixteen->id]);
        $this->value($second, $ram, ['attribute_value_id' => $thirtyTwo->id]);
        $tables = [
            'products',
            'product_attributes',
            'product_attribute_values',
            'category_product_attributes',
            'categories',
            'brands',
            'product_images',
            'supplier_products',
            'product_supplier_offers',
            'product_quality_flags',
            'product_quality_flag_assignments',
            'users',
            'roles',
            'permissions',
        ];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $timestamps = Product::query()->pluck('updated_at', 'id')->map(fn (mixed $value): string => (string) $value)->all();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->filtered(['ram' => ['16-gb']])->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->filtered(['ram' => ['16-gb']])->assertOk();

        $after = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $this->assertLessThanOrEqual(24, $queryCount);
        $this->assertSame($before, $after);
        $this->assertSame(
            $timestamps,
            Product::query()->pluck('updated_at', 'id')->map(fn (mixed $value): string => (string) $value)->all(),
        );
    }

    private function filtered(array $filters)
    {
        return $this->getJson('/api/v1/products?'.http_build_query(['attribute_filters' => $filters]));
    }

    private function product(Category $category, array $attributes = []): Product
    {
        return Product::factory()->create(['category_id' => $category->id, ...$attributes]);
    }

    private function attribute(
        string $code,
        string $label,
        string $type,
        int $position = 0,
        ?string $unit = null,
    ): ProductAttribute {
        return ProductAttribute::factory()->create([
            'attribute_group_id' => AttributeGroup::factory()->create(['is_active' => true])->id,
            'code' => $code,
            'slug' => str_replace('_', '-', $code),
            'name' => $label,
            'name_bg' => $label,
            'name_en' => null,
            'name_translations' => [],
            'type' => $type,
            'unit' => $unit,
            'sort_order' => $position,
            'is_filterable' => true,
            'is_visible_on_product' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, string>  $values
     * @return array<int, AttributeValue>
     */
    private function attributeOptions(ProductAttribute $attribute, array $values): array
    {
        return collect($values)
            ->map(fn (string $slug, string $label): AttributeValue => AttributeValue::factory()->create([
                'product_attribute_id' => $attribute->id,
                'value' => $label,
                'value_translations' => [],
                'slug' => $slug,
                'is_active' => true,
            ]))
            ->values()
            ->all();
    }

    private function assign(
        Category $category,
        ProductAttribute $attribute,
        int $position = 0,
        bool $filterable = true,
    ): CategoryProductAttribute {
        return CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_filterable' => $filterable,
            'is_visible_on_product' => true,
            'sort_order' => $position,
        ]);
    }

    private function value(Product $product, ProductAttribute $attribute, array $values): ProductAttributeValue
    {
        return ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => null,
            'custom_value' => null,
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_json' => null,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_filterable' => true,
            ...$values,
        ]);
    }
}
