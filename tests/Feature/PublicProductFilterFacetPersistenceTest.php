<?php

namespace Tests\Feature;

use App\Enums\CategoryAttributeFilterControl;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicProductFilterFacetPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_attribute_facets_survive_minimum_maximum_combined_and_empty_price_results(): void
    {
        $fixture = $this->catalogFixture();

        $cases = [
            [['price_min' => 1200], 2],
            [['price_max' => 1200], 1],
            [['price_min' => 900, 'price_max' => 1200], 1],
            [['price_min' => 3000, 'price_max' => 4000], 0],
        ];

        foreach ($cases as [$query, $expectedProducts]) {
            $response = $this->getJson('/api/v1/products?'.http_build_query($query))
                ->assertOk()
                ->assertJsonCount($expectedProducts, 'data');

            $this->assertCatalogFacets($response);
            $response
                ->assertJsonPath('price_filter.selected_min', $query['price_min'] ?? null)
                ->assertJsonPath('price_filter.selected_max', $query['price_max'] ?? null);
        }

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertTrue($fixture['first']->exists);
    }

    public function test_active_attribute_filters_do_not_narrow_attribute_facets_but_do_narrow_results_and_price_bounds(): void
    {
        $this->catalogFixture();
        $query = http_build_query([
            'price_min' => 900,
            'price_max' => 1200,
            'attribute_filters' => [
                'processor' => ['intel'],
                'ports' => ['usb-c', 'hdmi'],
            ],
        ]);

        $response = $this->getJson('/api/v1/products?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'intel-low')
            ->assertJsonPath('active_filters.0.key', 'processor')
            ->assertJsonPath('active_filters.0.values.0.key', 'intel')
            ->assertJsonPath('active_filters.1.key', 'ports')
            ->assertJsonCount(2, 'active_filters.1.values')
            ->assertJsonPath('price_filter.min', 1000)
            ->assertJsonPath('price_filter.max', 1500)
            ->assertJsonPath('price_filter.selected_min', 900)
            ->assertJsonPath('price_filter.selected_max', 1200);

        $this->assertCatalogFacets($response);
    }

    public function test_category_scope_preserves_inherited_override_and_disabled_controls_during_price_filtering(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $other = Category::factory()->create(['parent_id' => $parent->id]);
        $ram = $this->attribute('ram', ProductAttribute::TYPE_SELECT);
        $weight = $this->attribute('weight', ProductAttribute::TYPE_DECIMAL);
        $disabled = $this->attribute('disabled', ProductAttribute::TYPE_SELECT);
        [$ram16, $ram32] = $this->attributeOptions($ram, ['16 GB' => '16-gb', '32 GB' => '32-gb']);
        [$shown, $hidden] = $this->attributeOptions($disabled, ['Shown' => 'shown', 'Hidden' => 'hidden']);
        $this->assign($parent, $ram, CategoryAttributeFilterControl::Options);
        $this->assign($parent, $weight, CategoryAttributeFilterControl::RangeSlider);
        $this->assign($child, $weight, CategoryAttributeFilterControl::MinMax);
        $this->assign($parent, $disabled, CategoryAttributeFilterControl::Options);
        $this->assign($child, $disabled, CategoryAttributeFilterControl::Options, false);
        $low = $this->product($child, ['slug' => 'child-low', 'price' => 100]);
        $high = $this->product($child, ['slug' => 'child-high', 'price' => 200]);
        $foreign = $this->product($other, ['slug' => 'other-category', 'price' => 50]);
        $this->value($low, $ram, ['attribute_value_id' => $ram16->id]);
        $this->value($high, $ram, ['attribute_value_id' => $ram32->id]);
        $this->value($foreign, $ram, ['attribute_value_id' => $ram32->id]);
        $this->value($low, $weight, ['value_number' => 1]);
        $this->value($high, $weight, ['value_number' => 2]);
        $this->value($low, $disabled, ['attribute_value_id' => $shown->id]);
        $this->value($high, $disabled, ['attribute_value_id' => $hidden->id]);

        $response = $this->getJson("/api/v1/categories/{$child->slug}/products?price_max=150")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'child-low');
        $filters = collect($response->json('filters'))->keyBy('key');

        $this->assertSame(['ram', 'weight'], $filters->keys()->all());
        $this->assertSame('options', $filters['ram']['control']);
        $this->assertSame(['16-gb', '32-gb'], array_column($filters['ram']['options'], 'key'));
        $this->assertSame('min_max', $filters['weight']['control']);
        $this->assertSame(1, $filters['weight']['min']);
        $this->assertSame(2, $filters['weight']['max']);
        $this->assertFalse($filters->has('disabled'));
        $response->assertJsonMissing(['slug' => 'other-category']);
    }

    public function test_brand_scope_keeps_facets_stable_without_values_from_other_brands(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $otherBrand = Brand::factory()->create();
        $processor = $this->attribute('processor', ProductAttribute::TYPE_SELECT);
        [$intel, $amd, $other] = $this->attributeOptions($processor, [
            'Intel' => 'intel',
            'AMD' => 'amd',
            'Other' => 'other',
        ]);
        $this->assign($category, $processor, CategoryAttributeFilterControl::Options);
        $low = $this->product($category, ['brand_id' => $brand->id, 'slug' => 'brand-low', 'price' => 100]);
        $high = $this->product($category, ['brand_id' => $brand->id, 'slug' => 'brand-high', 'price' => 200]);
        $foreign = $this->product($category, ['brand_id' => $otherBrand->id, 'slug' => 'foreign-brand', 'price' => 50]);
        $this->value($low, $processor, ['attribute_value_id' => $intel->id]);
        $this->value($high, $processor, ['attribute_value_id' => $amd->id]);
        $this->value($foreign, $processor, ['attribute_value_id' => $other->id]);

        $response = $this->getJson("/api/v1/brands/{$brand->slug}/products?price_max=150")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'brand-low')
            ->assertJsonMissing(['slug' => 'foreign-brand']);

        $this->assertSame(
            ['intel', 'amd'],
            array_column($response->json('filters.0.options'), 'key'),
        );
    }

    public function test_facet_persistence_is_bounded_read_only_and_excludes_non_public_or_supplier_values(): void
    {
        $category = Category::factory()->create();
        $processor = $this->attribute('processor', ProductAttribute::TYPE_SELECT);
        [$intel, $amd, $private] = $this->attributeOptions($processor, [
            'Intel' => 'intel',
            'AMD' => 'amd',
            'Private' => 'private',
        ]);
        $this->assign($category, $processor, CategoryAttributeFilterControl::Options);
        foreach ([[$intel, 100], [$amd, 200]] as [$option, $price]) {
            $this->value($this->product($category, ['price' => $price]), $processor, ['attribute_value_id' => $option->id]);
        }
        $this->value(Product::factory()->manualDraft()->create(['category_id' => $category->id]), $processor, ['attribute_value_id' => $private->id]);
        $this->value($this->product($category, ['active' => false]), $processor, ['attribute_value_id' => $private->id]);
        $deleted = $this->product($category);
        $this->value($deleted, $processor, ['attribute_value_id' => $private->id]);
        $deleted->delete();
        $this->value($this->product($category), $processor, [
            'attribute_value_id' => $private->id,
            'source' => ProductAttributeValue::SOURCE_CONTROLLED_SYNC,
        ]);
        $corrupt = $this->assign(Category::factory()->create(), $this->attribute('weight', ProductAttribute::TYPE_DECIMAL), CategoryAttributeFilterControl::MinMax);
        DB::table('category_product_attributes')->where('id', $corrupt->id)->update(['filter_control_type' => 'yes_no']);

        $tables = ['products', 'product_attribute_values', 'category_product_attributes', 'supplier_products'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $timestamps = Product::query()->pluck('updated_at', 'id')->map(fn (mixed $value): string => (string) $value)->all();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/v1/products?price_max=150')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(['intel', 'amd'], array_column($response->json('filters.0.options'), 'key'));
        $this->assertLessThanOrEqual(24, $queryCount);
        $this->assertSame($before, collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all());
        $this->assertSame($timestamps, Product::query()->pluck('updated_at', 'id')->map(fn (mixed $value): string => (string) $value)->all());
        $this->assertSame('yes_no', DB::table('category_product_attributes')->where('id', $corrupt->id)->value('filter_control_type'));
        $response
            ->assertJsonMissing(['key' => 'private'])
            ->assertJsonMissingPath('supplier_products')
            ->assertJsonMissingPath('exception');
    }

    /**
     * @return array{first: Product}
     */
    private function catalogFixture(): array
    {
        $category = Category::factory()->create();
        $processor = $this->attribute('processor', ProductAttribute::TYPE_SELECT, 10);
        $ports = $this->attribute('ports', ProductAttribute::TYPE_MULTISELECT, 20);
        $wifi = $this->attribute('wifi', ProductAttribute::TYPE_BOOLEAN, 30);
        $weight = $this->attribute('weight', ProductAttribute::TYPE_DECIMAL, 40);
        $memory = $this->attribute('memory', ProductAttribute::TYPE_NUMBER, 50);
        [$intel, $amd] = $this->attributeOptions($processor, ['Intel' => 'intel', 'AMD' => 'amd']);
        [$usb, $hdmi] = $this->attributeOptions($ports, ['USB-C' => 'usb-c', 'HDMI' => 'hdmi']);
        $this->assign($category, $processor, CategoryAttributeFilterControl::Options, position: 10);
        $this->assign($category, $ports, CategoryAttributeFilterControl::Options, position: 20);
        $this->assign($category, $wifi, CategoryAttributeFilterControl::YesNo, position: 30);
        $this->assign($category, $weight, CategoryAttributeFilterControl::RangeSlider, position: 40);
        $this->assign($category, $memory, CategoryAttributeFilterControl::MinMax, position: 50);
        $first = $this->product($category, ['slug' => 'intel-low', 'price' => 1000]);
        $second = $this->product($category, ['slug' => 'amd-high', 'price' => 2000]);
        $third = $this->product($category, ['slug' => 'intel-middle', 'price' => 1500]);

        foreach ([
            [$first, $intel, [$usb->id], true, 1.4, 8],
            [$second, $amd, [$hdmi->id], false, 2.0, 16],
            [$third, $intel, [$usb->id, $hdmi->id], true, 1.7, 12],
        ] as [$product, $processorOption, $portIds, $hasWifi, $productWeight, $productMemory]) {
            $this->value($product, $processor, ['attribute_value_id' => $processorOption->id]);
            $this->value($product, $ports, ['value_json' => ['attribute_value_ids' => $portIds]]);
            $this->value($product, $wifi, ['value_boolean' => $hasWifi]);
            $this->value($product, $weight, ['value_number' => $productWeight]);
            $this->value($product, $memory, ['value_number' => $productMemory]);
        }

        return ['first' => $first];
    }

    private function assertCatalogFacets(TestResponse $response): void
    {
        $filters = collect($response->json('filters'))->keyBy('key');

        $this->assertSame(['processor', 'ports', 'wifi', 'weight', 'memory'], $filters->keys()->all());
        $this->assertSame(['intel', 'amd'], array_column($filters['processor']['options'], 'key'));
        $this->assertSame(['usb-c', 'hdmi'], array_column($filters['ports']['options'], 'key'));
        $this->assertSame(['yes', 'no'], array_column($filters['wifi']['options'], 'key'));
        $this->assertSame('range_slider', $filters['weight']['control']);
        $this->assertSame(1.4, $filters['weight']['min']);
        $this->assertSame(2, $filters['weight']['max']);
        $this->assertSame('min_max', $filters['memory']['control']);
        $this->assertSame(8, $filters['memory']['min']);
        $this->assertSame(16, $filters['memory']['max']);
    }

    private function attribute(string $code, string $type, int $position = 0): ProductAttribute
    {
        return ProductAttribute::factory()->create([
            'attribute_group_id' => AttributeGroup::factory()->create(['is_active' => true])->id,
            'code' => $code,
            'slug' => str_replace('_', '-', $code),
            'name' => $code,
            'name_bg' => $code,
            'type' => $type,
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
        $position = 0;

        return collect($values)->map(function (string $slug, string $label) use ($attribute, &$position): AttributeValue {
            return AttributeValue::factory()->create([
                'product_attribute_id' => $attribute->id,
                'value' => $label,
                'slug' => $slug,
                'is_active' => true,
                'sort_order' => $position++,
            ]);
        })->values()->all();
    }

    private function assign(
        Category $category,
        ProductAttribute $attribute,
        CategoryAttributeFilterControl $control,
        bool $filterable = true,
        int $position = 0,
    ): CategoryProductAttribute {
        return CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_filterable' => $filterable,
            'is_visible_on_product' => true,
            'filter_control_type' => $control->value,
            'sort_order' => $position,
        ]);
    }

    private function product(Category $category, array $attributes = []): Product
    {
        return Product::factory()->create(['category_id' => $category->id, ...$attributes]);
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
