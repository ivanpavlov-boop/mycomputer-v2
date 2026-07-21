<?php

namespace Tests\Feature;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Services\Products\PublicProductSpecificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicProductSpecificationDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_template_returns_only_valid_catalog_values_in_deterministic_group_and_item_order(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'slug' => 'direct-specifications']);
        $performance = AttributeGroup::factory()->create(['name' => 'Производителност', 'slug' => 'performance', 'sort_order' => 20]);
        $physical = AttributeGroup::factory()->create(['name' => 'Физически данни', 'slug' => 'physical', 'sort_order' => 10]);
        $memory = $this->attribute($performance, 'memory', 'Памет', ProductAttribute::TYPE_NUMBER, unit: 'GB', sortOrder: 20);
        $weight = $this->attribute($physical, 'weight', 'Тегло', ProductAttribute::TYPE_DECIMAL, unit: 'kg', sortOrder: 20);
        $width = $this->attribute($physical, 'width', 'Ширина', ProductAttribute::TYPE_NUMBER, unit: 'mm', sortOrder: 10);

        $this->assign($category, $memory, sortOrder: 30, required: true);
        $this->assign($category, $weight, sortOrder: 20);
        $this->assign($category, $width, sortOrder: 10);
        $this->value($product, $memory, ['value_number' => '16.0000']);
        $this->value($product, $weight, ['value_number' => '2.5000']);
        $this->value($product, $width, ['value_number' => '350.0000']);

        $response = $this->detail($product)->assertOk();

        $response
            ->assertJsonPath('data.specification_groups.0.key', 'physical')
            ->assertJsonPath('data.specification_groups.0.items.0.key', 'width')
            ->assertJsonPath('data.specification_groups.0.items.0.display_value', '350 mm')
            ->assertJsonPath('data.specification_groups.0.items.1.key', 'weight')
            ->assertJsonPath('data.specification_groups.0.items.1.display_value', '2.5 kg')
            ->assertJsonPath('data.specification_groups.1.key', 'performance')
            ->assertJsonPath('data.specification_groups.1.items.0.display_value', '16 GB')
            ->assertJsonMissingPath('data.specification_groups.0.id')
            ->assertJsonMissingPath('data.specification_groups.1.items.0.id')
            ->assertJsonMissingPath('data.specification_groups.1.items.0.is_required')
            ->assertJsonMissingPath('data.specification_groups.1.items.0.is_recommended');
    }

    public function test_inherited_template_and_closest_category_precedence_return_each_attribute_once(): void
    {
        $parent = Category::factory()->create(['name' => 'Компютри']);
        $child = Category::factory()->create(['parent_id' => $parent->id, 'name' => 'Лаптопи']);
        $product = Product::factory()->create(['category_id' => $child->id, 'slug' => 'inherited-specifications']);
        $group = AttributeGroup::factory()->create(['name' => 'Основни', 'slug' => 'main']);
        $processor = $this->attribute($group, 'processor', 'Процесор', ProductAttribute::TYPE_TEXT);
        $memory = $this->attribute($group, 'memory', 'Памет', ProductAttribute::TYPE_TEXT);

        $this->assign($parent, $processor, sortOrder: 50);
        $this->assign($parent, $memory, sortOrder: 20);
        $this->assign($child, $processor, sortOrder: 5);
        $this->value($product, $processor, ['value_text' => 'Intel Core Ultra 7']);
        $this->value($product, $memory, ['value_text' => '32 GB']);

        $json = $this->detail($product)->assertOk()->json('data.specification_groups');

        $this->assertSame(['processor', 'memory'], array_column($json[0]['items'], 'key'));
        $this->assertCount(1, array_filter($json[0]['items'], fn (array $item): bool => $item['key'] === 'processor'));
        $this->assertStringNotContainsString('inherited_template', json_encode($json, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('template', json_encode($json, JSON_THROW_ON_ERROR));
    }

    public function test_products_without_an_effective_template_or_values_return_no_public_groups(): void
    {
        $categoryWithoutTemplate = Category::factory()->create();
        $productWithoutTemplate = Product::factory()->create([
            'category_id' => $categoryWithoutTemplate->id,
            'slug' => 'without-template',
        ]);
        $categoryWithTemplate = Category::factory()->create();
        $productWithoutValues = Product::factory()->create([
            'category_id' => $categoryWithTemplate->id,
            'slug' => 'without-values',
        ]);
        $attribute = ProductAttribute::factory()->create();
        $this->assign($categoryWithTemplate, $attribute);

        $this->detail($productWithoutTemplate)
            ->assertOk()
            ->assertJsonPath('data.specification_groups', []);
        $this->detail($productWithoutValues)
            ->assertOk()
            ->assertJsonPath('data.specification_groups', []);

        $withoutCategory = Product::factory()->create(['category_id' => null]);
        $this->assertSame([], app(PublicProductSpecificationService::class)->groups($withoutCategory));
        $this->getJson('/api/v1/products/'.$withoutCategory->slug)->assertNotFound();
    }

    public function test_invalid_missing_hidden_out_of_template_and_supplier_derived_values_are_omitted(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'slug' => 'safe-eligibility']);
        $group = AttributeGroup::factory()->create();
        $valid = $this->attribute($group, 'valid', 'Валидна', ProductAttribute::TYPE_TEXT);
        $missing = $this->attribute($group, 'missing', 'Празна', ProductAttribute::TYPE_TEXT);
        $invalidSelect = $this->attribute($group, 'invalid_select', 'Невалиден избор', ProductAttribute::TYPE_SELECT);
        $invalidMultiselect = $this->attribute($group, 'invalid_multi', 'Невалиден списък', ProductAttribute::TYPE_MULTISELECT);
        $hidden = $this->attribute($group, 'hidden', 'Скрита', ProductAttribute::TYPE_TEXT, visible: false);
        $supplierDerived = $this->attribute($group, 'supplier_value', 'Доставчик', ProductAttribute::TYPE_TEXT);
        $outOfTemplate = $this->attribute($group, 'legacy_reference', 'Legacy', ProductAttribute::TYPE_TEXT);
        $otherAttribute = $this->attribute($group, 'other', 'Друга', ProductAttribute::TYPE_SELECT);
        $wrongOption = AttributeValue::factory()->create(['product_attribute_id' => $otherAttribute->id]);
        $validMultiOption = AttributeValue::factory()->create(['product_attribute_id' => $invalidMultiselect->id]);

        foreach ([$valid, $missing, $invalidSelect, $invalidMultiselect, $hidden, $supplierDerived] as $attribute) {
            $this->assign($category, $attribute);
        }

        $this->value($product, $valid, ['value_text' => 'Показва се']);
        $this->value($product, $missing, ['value_text' => '   ', 'custom_value' => '   ']);
        $this->value($product, $invalidSelect, ['attribute_value_id' => $wrongOption->id]);
        $this->value($product, $invalidMultiselect, [
            'value_json' => ['attribute_value_ids' => [$validMultiOption->id, $wrongOption->id]],
        ]);
        $this->value($product, $hidden, ['value_text' => 'Не се показва']);
        $this->value($product, $supplierDerived, [
            'value_text' => 'От доставчик',
            'source' => ProductAttributeValue::SOURCE_CONTROLLED_SYNC,
        ]);
        $this->value($product, $outOfTemplate, ['value_text' => 'Reference only']);

        $encoded = json_encode(
            $this->detail($product)->assertOk()->json('data.specification_groups'),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        $this->assertStringContainsString('Показва се', $encoded);
        foreach (['Празна', 'Невалиден избор', 'Невалиден списък', 'Скрита', 'От доставчик', 'Reference only'] as $hiddenValue) {
            $this->assertStringNotContainsString($hiddenValue, $encoded);
        }
    }

    public function test_public_value_types_labels_units_localization_and_escaping_are_formatted_for_customers(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'slug' => 'formatted-specifications']);
        $group = AttributeGroup::factory()->create([
            'name' => 'Екран <script>',
            'name_translations' => ['en' => 'Display'],
            'slug' => 'display',
        ]);
        $text = $this->attribute($group, 'unsafe_text', 'Име <img onerror=x>', ProductAttribute::TYPE_TEXT);
        $yes = $this->attribute($group, 'yes', 'Подсветка', ProductAttribute::TYPE_BOOLEAN);
        $no = $this->attribute($group, 'no', 'Тъч', ProductAttribute::TYPE_BOOLEAN);
        $select = $this->attribute($group, 'panel', 'Панел', ProductAttribute::TYPE_SELECT);
        $multi = $this->attribute($group, 'ports', 'Портове', ProductAttribute::TYPE_MULTISELECT);
        $json = $this->attribute($group, 'modes', 'Режими', ProductAttribute::TYPE_JSON);
        $unitAlreadyPresent = $this->attribute($group, 'capacity', 'Капацитет', ProductAttribute::TYPE_TEXT, unit: 'GB');
        $ips = AttributeValue::factory()->create([
            'product_attribute_id' => $select->id,
            'value' => 'IPS <b>',
            'value_translations' => ['en' => 'IPS'],
        ]);
        $usb = AttributeValue::factory()->create([
            'product_attribute_id' => $multi->id,
            'value' => 'USB-C',
            'sort_order' => 20,
        ]);
        $hdmi = AttributeValue::factory()->create([
            'product_attribute_id' => $multi->id,
            'value' => 'HDMI',
            'sort_order' => 10,
        ]);

        foreach ([$text, $yes, $no, $select, $multi, $json, $unitAlreadyPresent] as $index => $attribute) {
            $this->assign($category, $attribute, sortOrder: $index);
        }

        $this->value($product, $text, ['value_text' => '  <script>alert("x")</script> & текст  ']);
        $this->value($product, $yes, ['value_boolean' => true]);
        $this->value($product, $no, ['value_boolean' => false]);
        $this->value($product, $select, ['attribute_value_id' => $ips->id]);
        $this->value($product, $multi, ['value_json' => ['attribute_value_ids' => [$usb->id, $hdmi->id]]]);
        $this->value($product, $json, ['value_json' => ['eco', 'gaming']]);
        $this->value($product, $unitAlreadyPresent, ['value_text' => '16 GB']);

        $response = $this->detail($product)->assertOk();
        $items = collect($response->json('data.specification_groups.0.items'))->keyBy('key');

        $this->assertSame('<script>alert("x")</script> & текст', $items['unsafe_text']['display_value']);
        $this->assertSame('Да', $items['yes']['display_value']);
        $this->assertSame('Не', $items['no']['display_value']);
        $this->assertSame('IPS <b>', $items['panel']['display_value']);
        $this->assertSame('HDMI, USB-C', $items['ports']['display_value']);
        $this->assertSame('eco, gaming', $items['modes']['display_value']);
        $this->assertSame('16 GB', $items['capacity']['display_value']);

        $english = $this->withHeader('X-Locale', 'en')->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertSame('Display', $english->json('data.specification_groups.0.label'));
        $this->assertSame('IPS', collect($english->json('data.specification_groups.0.items'))->keyBy('key')['panel']['display_value']);
        $this->assertSame('Име <img onerror=x>', collect($english->json('data.specification_groups.0.items'))->keyBy('key')['unsafe_text']['label']);
    }

    public function test_inactive_and_soft_deleted_catalog_definitions_are_not_public(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'slug' => 'inactive-definitions']);
        $group = AttributeGroup::factory()->create();
        $select = $this->attribute($group, 'select', 'Избор', ProductAttribute::TYPE_SELECT);
        $this->assign($category, $select);
        $inactive = AttributeValue::factory()->create(['product_attribute_id' => $select->id, 'is_active' => false]);
        $this->value($product, $select, ['attribute_value_id' => $inactive->id]);

        $this->detail($product)->assertOk()->assertJsonPath('data.specification_groups', []);

        $inactive->update(['is_active' => true]);
        $group->update(['is_active' => false]);
        $this->detail($product)->assertOk()->assertJsonPath('data.specification_groups', []);

        $group->update(['is_active' => true]);
        $select->delete();
        $this->detail($product)->assertOk()->assertJsonPath('data.specification_groups', []);
    }

    public function test_unpublished_products_never_expose_specifications_and_list_payloads_stay_compact(): void
    {
        $category = Category::factory()->create();
        $public = Product::factory()->create(['category_id' => $category->id, 'slug' => 'public-card-safety']);
        $group = AttributeGroup::factory()->create();
        $attribute = $this->attribute($group, 'public-value', 'Публична', ProductAttribute::TYPE_TEXT);
        $this->assign($category, $attribute);
        $this->value($public, $attribute, ['value_text' => 'Стойност']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonMissingPath('data.0.specification_groups');

        foreach ([
            ['workflow_status' => Product::WORKFLOW_DRAFT],
            ['workflow_status' => Product::WORKFLOW_PENDING_REVIEW],
            ['workflow_status' => Product::WORKFLOW_APPROVED],
            ['active' => false],
            ['product_status' => 'hidden'],
            ['published_at' => null],
        ] as $index => $state) {
            $hidden = Product::factory()->create(array_merge($state, [
                'category_id' => $category->id,
                'slug' => 'hidden-specifications-'.$index,
            ]));
            $this->value($hidden, $attribute, ['value_text' => 'Secret']);
            $this->getJson('/api/v1/products/'.$hidden->slug)->assertNotFound();
        }
    }

    public function test_public_response_exposes_no_quality_supplier_workflow_or_audit_metadata(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'slug' => 'metadata-safety',
            'specifications' => ['Legacy CPU' => 'Do not expose'],
        ]);
        $attribute = ProductAttribute::factory()->create(['type' => ProductAttribute::TYPE_TEXT]);
        $this->assign($category, $attribute, required: true);
        $this->value($product, $attribute, ['value_text' => 'Catalog value']);

        $encoded = $this->detail($product)->assertOk()->getContent();

        foreach (['missing_required', 'needs_data', 'no_category_template', 'quality_flag', 'supplier_id', 'source_payload', 'workflow_status', 'verified_by', 'Do not expose'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_product_detail_specification_queries_are_bounded_as_values_grow(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'slug' => 'bounded-specifications']);

        foreach (range(1, 3) as $groupPosition) {
            $group = AttributeGroup::factory()->create(['sort_order' => $groupPosition]);

            foreach (range(1, 8) as $position) {
                $attribute = $this->attribute($group, "attribute-{$groupPosition}-{$position}", "Атрибут {$groupPosition}-{$position}", ProductAttribute::TYPE_TEXT);
                $this->assign($category, $attribute, sortOrder: $position);
                $this->value($product, $attribute, ['value_text' => "Стойност {$position}"]);
            }
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->detail($product)->assertOk()->assertJsonCount(3, 'data.specification_groups');

        $this->assertLessThanOrEqual(20, $queryCount, "Product detail used {$queryCount} queries for 24 specifications.");
    }

    public function test_service_and_repeated_public_requests_do_not_mutate_catalog_or_security_tables(): void
    {
        $this->seed();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'slug' => 'read-only-specifications']);
        $attribute = ProductAttribute::factory()->create(['type' => ProductAttribute::TYPE_TEXT]);
        $this->assign($category, $attribute);
        $this->value($product, $attribute, ['value_text' => 'Read only']);
        $before = $this->protectedSnapshot();

        $service = app(PublicProductSpecificationService::class);
        $first = $service->groups($product->fresh(), 'bg');
        $second = $service->groups($product->fresh(), 'bg');
        $this->detail($product)->assertOk();
        $this->detail($product)->assertOk();

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->protectedSnapshot());
    }

    private function detail(Product $product): TestResponse
    {
        return $this->withHeader('X-Locale', 'bg')->getJson('/api/v1/products/'.$product->slug);
    }

    private function attribute(
        AttributeGroup $group,
        string $code,
        string $label,
        string $type,
        ?string $unit = null,
        int $sortOrder = 0,
        bool $visible = true,
    ): ProductAttribute {
        return ProductAttribute::factory()->create([
            'attribute_group_id' => $group->id,
            'code' => $code,
            'slug' => str_replace('_', '-', $code),
            'name' => $label,
            'name_bg' => $label,
            'name_en' => null,
            'name_translations' => null,
            'type' => $type,
            'unit' => $unit,
            'sort_order' => $sortOrder,
            'is_visible_on_product' => $visible,
            'is_active' => true,
        ]);
    }

    private function assign(
        Category $category,
        ProductAttribute $attribute,
        int $sortOrder = 0,
        bool $required = false,
    ): CategoryProductAttribute {
        return CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'sort_order' => $sortOrder,
            'is_required' => $required,
            'is_visible_on_product' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function value(Product $product, ProductAttribute $attribute, array $attributes): ProductAttributeValue
    {
        return ProductAttributeValue::factory()->create(array_merge([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => null,
            'custom_value' => null,
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_json' => null,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
        ], $attributes));
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function protectedSnapshot(): array
    {
        return collect([
            'products',
            'product_attribute_values',
            'product_attributes',
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
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ])->all();
    }
}
