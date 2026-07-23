<?php

namespace Tests\Feature;

use App\Enums\CategoryAttributeFilterControl;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\AvailabilityStatus;
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

class PublicProductPriceFacetPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_supported_attribute_control_preserves_the_broader_catalog_price_facet(): void
    {
        $this->catalogFixture();
        $cases = [
            [['processor' => ['amd']], 1, 'amd-high'],
            [['ports' => ['usb-c']], 2, null],
            [['wifi' => ['no']], 1, 'amd-high'],
            [['weight' => ['min' => '1.4', 'max' => '1.4']], 1, 'intel-low'],
            [['memory' => ['min' => '8', 'max' => '8']], 1, 'intel-low'],
        ];

        foreach ($cases as [$attributes, $expectedCount, $expectedSlug]) {
            $response = $this->filtered('/api/v1/products', ['attribute_filters' => $attributes])
                ->assertOk()
                ->assertJsonCount($expectedCount, 'data');

            if ($expectedSlug !== null) {
                $response->assertJsonPath('data.0.slug', $expectedSlug);
            }

            $response
                ->assertJsonPath('price_filter.min', 1000)
                ->assertJsonPath('price_filter.max', 2000)
                ->assertJsonPath('active_filters.0.key', array_key_first($attributes));
        }
    }

    public function test_one_and_zero_result_attribute_selections_keep_price_and_attribute_facets_recoverable(): void
    {
        $this->catalogFixture();

        $one = $this->filtered('/api/v1/products', [
            'attribute_filters' => ['processor' => ['amd']],
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'amd-high')
            ->assertJsonPath('price_filter.min', 1000)
            ->assertJsonPath('price_filter.max', 2000)
            ->assertJsonCount(1, 'active_filters');
        $this->assertCatalogFacets($one);

        $zero = $this->filtered('/api/v1/products', [
            'attribute_filters' => [
                'processor' => ['amd'],
                'wifi' => ['yes'],
            ],
        ])->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('price_filter.min', 1000)
            ->assertJsonPath('price_filter.max', 2000)
            ->assertJsonCount(2, 'active_filters');
        $this->assertCatalogFacets($zero);
    }

    public function test_result_query_combines_attribute_and_price_bounds_without_narrowing_either_facet(): void
    {
        $this->catalogFixture();
        $cases = [
            [['price_min' => 1200], 1, 'intel-middle'],
            [['price_max' => 1200], 1, 'intel-low'],
            [['price_min' => 1200, 'price_max' => 1400], 0, null],
        ];

        foreach ($cases as [$price, $expectedCount, $expectedSlug]) {
            $response = $this->filtered('/api/v1/products', [
                'attribute_filters' => ['processor' => ['intel']],
                ...$price,
            ])->assertOk()
                ->assertJsonCount($expectedCount, 'data')
                ->assertJsonPath('price_filter.min', 1000)
                ->assertJsonPath('price_filter.max', 2000)
                ->assertJsonPath('price_filter.selected_min', $price['price_min'] ?? null)
                ->assertJsonPath('price_filter.selected_max', $price['price_max'] ?? null)
                ->assertJsonPath('active_filters.0.key', 'processor');

            if ($expectedSlug !== null) {
                $response->assertJsonPath('data.0.slug', $expectedSlug);
            }

            $this->assertCatalogFacets($response);
        }
    }

    public function test_category_and_brand_endpoints_ignore_attributes_but_keep_their_hard_price_scope(): void
    {
        $fixture = $this->catalogFixture();
        $otherCategory = Category::factory()->create();
        $otherBrand = Brand::factory()->create();
        $foreignCategory = $this->product($otherCategory, [
            'brand_id' => $fixture['brand']->id,
            'slug' => 'foreign-category',
            'price' => 50,
        ]);
        $foreignBrand = $this->product($fixture['category'], [
            'brand_id' => $otherBrand->id,
            'slug' => 'foreign-brand',
            'price' => 3000,
        ]);
        $this->value($foreignCategory, $fixture['processor'], ['attribute_value_id' => $fixture['amd']->id]);
        $this->value($foreignBrand, $fixture['processor'], ['attribute_value_id' => $fixture['amd']->id]);
        $query = ['attribute_filters' => ['processor' => ['amd']]];

        $this->filtered("/api/v1/categories/{$fixture['category']->slug}/products", $query)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('price_filter.min', 1000)
            ->assertJsonPath('price_filter.max', 3000)
            ->assertJsonMissing(['slug' => 'foreign-category']);

        $this->filtered("/api/v1/brands/{$fixture['brand']->slug}/products", $query)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'amd-high')
            ->assertJsonPath('price_filter.min', 50)
            ->assertJsonPath('price_filter.max', 2000)
            ->assertJsonMissing(['slug' => 'foreign-brand']);
    }

    public function test_search_and_availability_still_constrain_the_price_facet_before_attributes_are_ignored(): void
    {
        $this->catalogFixture();
        $attribute = ['memory' => ['min' => '8', 'max' => '8']];

        $this->filtered('/api/v1/products', [
            'search' => 'Scoped',
            'attribute_filters' => $attribute,
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'intel-low')
            ->assertJsonPath('price_filter.min', 1000)
            ->assertJsonPath('price_filter.max', 1500);

        $this->filtered('/api/v1/products', [
            'availability' => 'available',
            'attribute_filters' => $attribute,
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'intel-low')
            ->assertJsonPath('price_filter.min', 1000)
            ->assertJsonPath('price_filter.max', 1500);
    }

    public function test_equal_or_missing_prices_still_hide_the_price_facet(): void
    {
        $equal = Category::factory()->create();
        $this->product($equal, ['price' => 100]);
        $this->product($equal, ['price' => 100]);

        $this->getJson("/api/v1/categories/{$equal->slug}/products")
            ->assertOk()
            ->assertJsonPath('price_filter', null);

        $missing = Category::factory()->create();

        $this->getJson("/api/v1/categories/{$missing->slug}/products")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('price_filter', null);
    }

    public function test_price_facet_evaluation_is_bounded_read_only_and_keeps_sync_safety_locked(): void
    {
        $this->catalogFixture();
        $tables = [
            'products',
            'product_attribute_values',
            'category_product_attributes',
            'supplier_products',
        ];
        $before = collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        )->all();
        $timestamps = Product::query()->pluck('updated_at', 'id')->map(
            fn (mixed $value): string => (string) $value,
        )->all();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->filtered('/api/v1/products', [
            'attribute_filters' => ['processor' => ['amd']],
        ])->assertOk()
            ->assertJsonPath('price_filter.min', 1000)
            ->assertJsonPath('price_filter.max', 2000);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(24, $queryCount);
        $this->assertSame($before, collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        )->all());
        $this->assertSame($timestamps, Product::query()->pluck('updated_at', 'id')->map(
            fn (mixed $value): string => (string) $value,
        )->all());
        $response
            ->assertJsonMissingPath('supplier_products')
            ->assertJsonMissingPath('price_filter.supplier_cost')
            ->assertJsonMissingPath('price_filter.purchase_price');
        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    /**
     * @return array{category: Category, brand: Brand, processor: ProductAttribute, amd: AttributeValue}
     */
    private function catalogFixture(): array
    {
        $available = $this->availability('available');
        $unavailable = $this->availability('unavailable');
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $processor = $this->attribute('processor', ProductAttribute::TYPE_SELECT, 10);
        $ports = $this->attribute('ports', ProductAttribute::TYPE_MULTISELECT, 20);
        $wifi = $this->attribute('wifi', ProductAttribute::TYPE_BOOLEAN, 30);
        $weight = $this->attribute('weight', ProductAttribute::TYPE_DECIMAL, 40);
        $memory = $this->attribute('memory', ProductAttribute::TYPE_NUMBER, 50);
        [$intel, $amd] = $this->attributeOptions($processor, ['Intel' => 'intel', 'AMD' => 'amd']);
        [$usb, $hdmi] = $this->attributeOptions($ports, ['USB-C' => 'usb-c', 'HDMI' => 'hdmi']);
        $this->assign($category, $processor, CategoryAttributeFilterControl::Options, 10);
        $this->assign($category, $ports, CategoryAttributeFilterControl::Options, 20);
        $this->assign($category, $wifi, CategoryAttributeFilterControl::YesNo, 30);
        $this->assign($category, $weight, CategoryAttributeFilterControl::RangeSlider, 40);
        $this->assign($category, $memory, CategoryAttributeFilterControl::MinMax, 50);
        $first = $this->product($category, [
            'brand_id' => $brand->id,
            'availability_status_id' => $available->id,
            'name' => 'Scoped low',
            'slug' => 'intel-low',
            'price' => 1000,
        ]);
        $second = $this->product($category, [
            'brand_id' => $brand->id,
            'availability_status_id' => $unavailable->id,
            'name' => 'Other high',
            'slug' => 'amd-high',
            'price' => 2000,
        ]);
        $third = $this->product($category, [
            'brand_id' => $brand->id,
            'availability_status_id' => $available->id,
            'name' => 'Scoped middle',
            'slug' => 'intel-middle',
            'price' => 1500,
        ]);

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

        return compact('category', 'brand', 'processor', 'amd');
    }

    private function assertCatalogFacets(TestResponse $response): void
    {
        $filters = collect($response->json('filters'))->keyBy('key');

        $this->assertSame(['processor', 'ports', 'wifi', 'weight', 'memory'], $filters->keys()->all());
        $this->assertSame(['intel', 'amd'], array_column($filters['processor']['options'], 'key'));
        $this->assertSame(['usb-c', 'hdmi'], array_column($filters['ports']['options'], 'key'));
        $this->assertSame(['yes', 'no'], array_column($filters['wifi']['options'], 'key'));
        $this->assertSame('range_slider', $filters['weight']['control']);
        $this->assertSame('min_max', $filters['memory']['control']);
    }

    private function filtered(string $endpoint, array $query): TestResponse
    {
        return $this->getJson($endpoint.'?'.http_build_query($query));
    }

    private function availability(string $code): AvailabilityStatus
    {
        return AvailabilityStatus::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'color' => 'green',
            'icon' => 'check',
            'badge_style' => 'solid',
            'allow_purchase' => true,
            'show_stock_quantity' => true,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function attribute(string $code, string $type, int $position): ProductAttribute
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
        int $position,
    ): CategoryProductAttribute {
        return CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_filterable' => true,
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
            'value_boolean' => null,
            'value_json' => null,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_filterable' => true,
            ...$values,
        ]);
    }
}
