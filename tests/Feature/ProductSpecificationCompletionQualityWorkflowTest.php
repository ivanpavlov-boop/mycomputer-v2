<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\CategoryProductAttributes\CategoryProductAttributeResource;
use App\Filament\Resources\ProductDataQualityQueue\Pages\ListProductDataQualityQueue;
use App\Filament\Resources\ProductDataQualityQueue\Widgets\ProductDataQualityQueueStats;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Products\CategorySpecificationTemplateResolver;
use App\Services\Products\CategorySpecificationTemplateResult;
use App\Services\Products\ProductDataQualitySummaryService;
use App\Services\Products\ProductSpecificationQualityResult;
use App\Services\Products\ProductSpecificationQualityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductSpecificationCompletionQualityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_template_coverage_uses_one_direct_and_inherited_resolution_path(): void
    {
        $parent = Category::factory()->create(['name' => 'Компютри']);
        $child = Category::factory()->create(['name' => 'Лаптопи', 'parent_id' => $parent->id]);
        $grandchild = Category::factory()->create(['name' => 'Гейминг', 'parent_id' => $child->id]);
        $withoutTemplate = Category::factory()->create(['name' => 'Други']);
        $ram = $this->attribute('RAM', ProductAttribute::TYPE_TEXT);
        $color = $this->attribute('Цвят', ProductAttribute::TYPE_TEXT);

        CategoryProductAttribute::factory()->create([
            'category_id' => $parent->id,
            'product_attribute_id' => $ram->id,
            'is_required' => true,
            'sort_order' => 1,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $parent->id,
            'product_attribute_id' => $color->id,
            'is_required' => false,
            'is_visible_on_product' => true,
            'sort_order' => 2,
        ]);
        CategoryProductAttribute::factory()->create([
            'category_id' => $child->id,
            'product_attribute_id' => $color->id,
            'is_required' => true,
            'sort_order' => 1,
        ]);
        $before = $this->protectedSnapshot();
        $resolver = app(CategorySpecificationTemplateResolver::class);

        $parentResult = $resolver->resolve($parent);
        $childResult = $resolver->resolve($child);
        $grandchildResult = $resolver->resolve($grandchild);
        $missingResult = $resolver->resolve($withoutTemplate);

        $this->assertSame(CategorySpecificationTemplateResult::STATUS_DIRECT_TEMPLATE, $parentResult->status);
        $this->assertSame($parent->id, $parentResult->sourceCategory?->id);
        $this->assertSame(2, $parentResult->directAttributeCount());
        $this->assertSame(1, $parentResult->requiredAttributeCount());
        $this->assertSame(1, $parentResult->recommendedAttributeCount());

        $this->assertSame(CategorySpecificationTemplateResult::STATUS_DIRECT_TEMPLATE, $childResult->status);
        $this->assertSame($child->id, $childResult->sourceCategory?->id);
        $this->assertSame(1, $childResult->directAttributeCount());
        $this->assertSame(1, $childResult->inheritedAttributeCount());
        $this->assertSame(2, $childResult->effectiveAttributeCount());
        $this->assertSame([$color->id, $ram->id], $childResult->effectiveAssignments->pluck('product_attribute_id')->all());
        $this->assertSame(2, $childResult->requiredAttributeCount());

        $this->assertSame(CategorySpecificationTemplateResult::STATUS_INHERITED_TEMPLATE, $grandchildResult->status);
        $this->assertSame($child->id, $grandchildResult->sourceCategory?->id);
        $this->assertSame(['Компютри', 'Лаптопи', 'Гейминг'], $grandchildResult->categoryPath);
        $this->assertSame(2, $grandchildResult->effectiveAttributeCount());
        $this->assertSame(2, $grandchildResult->effectiveAssignments->pluck('product_attribute_id')->unique()->count());

        $this->assertSame(CategorySpecificationTemplateResult::STATUS_NO_TEMPLATE, $missingResult->status);
        $this->assertSame('Неизвестно', CategorySpecificationTemplateResult::labelFor('future_state'));
        $this->assertSame('gray', CategorySpecificationTemplateResult::colorFor('future_state'));
        $this->assertSame($before, $this->protectedSnapshot());
    }

    public function test_required_recommended_total_and_invalid_value_details_reuse_authoritative_validation(): void
    {
        $category = Category::factory()->create();
        $ram = $this->attribute('RAM', ProductAttribute::TYPE_TEXT);
        $color = $this->attribute('Цвят', ProductAttribute::TYPE_SELECT);
        $ports = $this->attribute('Портове', ProductAttribute::TYPE_MULTISELECT);
        $other = $this->attribute('Панел', ProductAttribute::TYPE_SELECT);
        $black = AttributeValue::factory()->create(['product_attribute_id' => $color->id, 'value' => 'Черен']);
        $usb = AttributeValue::factory()->create(['product_attribute_id' => $ports->id, 'value' => 'USB']);
        $hdmi = AttributeValue::factory()->create(['product_attribute_id' => $ports->id, 'value' => 'HDMI']);
        $wrong = AttributeValue::factory()->create(['product_attribute_id' => $other->id, 'value' => 'IPS']);

        $this->assign($category, $ram, required: true, order: 1);
        $this->assign($category, $color, required: true, order: 2);
        $this->assign($category, $ports, required: false, order: 3);
        $invalid = Product::factory()->create(['category_id' => $category->id]);
        $this->textValue($invalid, $ram, '16 GB');
        ProductAttributeValue::factory()->create([
            'product_id' => $invalid->id,
            'product_attribute_id' => $color->id,
            'attribute_value_id' => $wrong->id,
        ]);
        ProductAttributeValue::factory()->create([
            'product_id' => $invalid->id,
            'product_attribute_id' => $ports->id,
            'value_json' => ['attribute_value_ids' => [$usb->id, $wrong->id]],
        ]);

        $invalidResult = app(ProductSpecificationQualityService::class)->evaluate($invalid);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, $invalidResult->status);
        $this->assertSame('1/2 · 50%', $invalidResult->requiredScoreLabel());
        $this->assertSame('0/1 · 0%', $invalidResult->recommendedScoreLabel());
        $this->assertSame('1/3 · 33%', $invalidResult->compactScoreLabel());
        $this->assertSame(['Цвят'], $invalidResult->invalidRequiredAttributeLabels());
        $this->assertSame(['Портове'], $invalidResult->invalidRecommendedAttributeLabels());

        $complete = Product::factory()->create(['category_id' => $category->id]);
        $this->textValue($complete, $ram, '32 GB');
        ProductAttributeValue::factory()->create([
            'product_id' => $complete->id,
            'product_attribute_id' => $color->id,
            'attribute_value_id' => $black->id,
        ]);
        ProductAttributeValue::factory()->create([
            'product_id' => $complete->id,
            'product_attribute_id' => $ports->id,
            'value_json' => ['attribute_value_ids' => [$usb->id, $hdmi->id, $usb->id]],
        ]);

        $completeResult = app(ProductSpecificationQualityService::class)->evaluate($complete);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_GOOD, $completeResult->status);
        $this->assertSame('2/2 · 100%', $completeResult->requiredScoreLabel());
        $this->assertSame('1/1 · 100%', $completeResult->recommendedScoreLabel());
        $this->assertSame('3/3 · 100%', $completeResult->compactScoreLabel());

        $invalidRecommended = Product::factory()->create(['category_id' => $category->id]);
        $this->textValue($invalidRecommended, $ram, '24 GB');
        ProductAttributeValue::factory()->create([
            'product_id' => $invalidRecommended->id,
            'product_attribute_id' => $color->id,
            'attribute_value_id' => $black->id,
        ]);
        ProductAttributeValue::factory()->create([
            'product_id' => $invalidRecommended->id,
            'product_attribute_id' => $ports->id,
            'value_json' => ['attribute_value_ids' => [$usb->id, $wrong->id]],
        ]);
        $service = app(ProductSpecificationQualityService::class);

        $this->assertSame(
            [$invalid->id],
            $service->applyStateQuery(Product::query(), ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED)
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [$invalidRecommended->id],
            $service->applyStateQuery(Product::query(), ProductSpecificationQualityResult::STATUS_NEEDS_DATA)
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [$complete->id],
            $service->applyStateQuery(Product::query(), ProductSpecificationQualityResult::STATUS_GOOD)
                ->pluck('id')
                ->all(),
        );
    }

    public function test_queue_filters_presentation_and_statistics_use_the_same_specification_states(): void
    {
        $this->actingAsSuperAdmin();
        $category = Category::factory()->create(['name' => 'Лаптопи']);
        $emptyCategory = Category::factory()->create(['name' => 'Без шаблон']);
        $ram = $this->attribute('RAM', ProductAttribute::TYPE_TEXT);
        $color = $this->attribute('Цвят', ProductAttribute::TYPE_TEXT);
        $this->assign($category, $ram, required: true, order: 1);
        $this->assign($category, $color, required: false, order: 2);
        $missingRequired = Product::factory()->create(['category_id' => $category->id, 'ean' => null]);
        $needsData = Product::factory()->create(['category_id' => $category->id, 'ean' => null]);
        $good = Product::factory()->create(['category_id' => $category->id, 'ean' => null]);
        $noTemplate = Product::factory()->create(['category_id' => $emptyCategory->id, 'ean' => null]);
        $this->textValue($needsData, $ram, '16 GB');
        $this->textValue($good, $ram, '32 GB');
        $this->textValue($good, $color, 'Черен');
        $records = collect([$missingRequired, $needsData, $good, $noTemplate]);
        $service = app(ProductSpecificationQualityService::class);
        $expectedByStatus = [
            ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED => [$missingRequired->id],
            ProductSpecificationQualityResult::STATUS_NEEDS_DATA => [$needsData->id],
            ProductSpecificationQualityResult::STATUS_GOOD => [$good->id],
            ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE => [$noTemplate->id],
        ];

        foreach ($expectedByStatus as $status => $expectedIds) {
            $this->assertSame(
                $expectedIds,
                $service->applyStateQuery(Product::query()->whereKey($records->pluck('id')), $status)
                    ->orderBy('id')
                    ->pluck('id')
                    ->all(),
            );
        }

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertTableColumnStateSet('specification_template_source', 'Шаблон: Директен', $missingRequired)
            ->assertTableColumnStateSet('required_specification_completion', 'Задължителни: 0/1 · 0%', $missingRequired)
            ->assertTableColumnStateSet('recommended_specification_completion', 'Препоръчителни: 0/1 · 0%', $missingRequired)
            ->assertTableColumnStateSet('total_specification_completion', 'Общо: 0/2 · 0%', $missingRequired)
            ->assertTableColumnStateSet('specification_completion_quality', 'Липсват важни характеристики', $missingRequired)
            ->filterTable('specification_quality_state', ProductSpecificationQualityResult::STATUS_NEEDS_DATA)
            ->assertCanSeeTableRecords([$needsData])
            ->assertCanNotSeeTableRecords([$missingRequired, $good, $noTemplate]);

        Livewire::test(ProductDataQualityQueueStats::class)
            ->assertSee('Липсват задължителни характеристики')
            ->assertSee('Непълни препоръчителни характеристики')
            ->assertSee('Няма шаблон за категорията')
            ->assertSee('Характеристиките са попълнени');
    }

    public function test_category_admin_shows_coverage_filters_and_existing_template_navigation(): void
    {
        $this->actingAsSuperAdmin();
        $unsafeParent = Category::factory()->create(['name' => '<script>alert("x")</script>']);
        $child = Category::factory()->create(['name' => 'Лаптопи', 'parent_id' => $unsafeParent->id]);
        $missing = Category::factory()->create(['name' => 'Други']);
        $ram = $this->attribute('RAM', ProductAttribute::TYPE_TEXT);
        $this->assign($unsafeParent, $ram, required: true);
        $before = $this->protectedSnapshot();

        Livewire::test(ListCategories::class)
            ->assertTableColumnStateSet('specification_template_coverage', 'Директен шаблон', $unsafeParent)
            ->assertTableColumnStateSet('specification_template_coverage', 'Наследен шаблон', $child)
            ->assertTableColumnStateSet('specification_template_coverage', 'Няма зададен шаблон', $missing)
            ->assertTableColumnStateSet('direct_specification_attributes', 0, $child)
            ->assertTableColumnStateSet('effective_specification_attributes', 1, $child)
            ->assertTableColumnStateSet('required_specification_attributes', 1, $child)
            ->assertTableActionVisible('manageSpecificationTemplate', $child)
            ->filterTable('specification_template_coverage', CategorySpecificationTemplateResult::STATUS_INHERITED_TEMPLATE)
            ->assertCanSeeTableRecords([$child])
            ->assertCanNotSeeTableRecords([$unsafeParent, $missing])
            ->assertDontSee('<script>', escape: false);

        $this->assertStringContainsString(
            CategoryProductAttributeResource::getUrl('index'),
            CategoryProductAttributeResource::getUrl('index', [
                'tableFilters' => ['category' => ['value' => $child->id]],
            ]),
        );
        $this->assertSame($before, $this->protectedSnapshot());
    }

    public function test_product_summary_is_escaped_read_only_and_does_not_double_count_missing_category(): void
    {
        $this->actingAsSuperAdmin();
        $withoutCategory = Product::factory()->create(['category_id' => null]);
        $summary = app(ProductDataQualitySummaryService::class)->summarize($withoutCategory);

        $this->assertSame(ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE, $summary->specificationResult->status);
        $this->assertSame('Липсва категория', $summary->specificationResult->reasonLabel());
        $this->assertContains('Задайте категория', $summary->nextSteps);
        $this->assertNotContains('Създайте или наследете шаблон за категорията', $summary->nextSteps);

        $category = Category::factory()->create(['name' => 'Категория <img onerror=alert(1)>']);
        $unsafe = $this->attribute('RAM <script>alert(1)</script>', ProductAttribute::TYPE_TEXT);
        $this->assign($category, $unsafe, required: true);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $before = $this->protectedSnapshot();

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('Качество на продуктовите данни')
            ->assertSee('Шаблон:')
            ->assertSee('Задължителни:')
            ->assertSee('Препоръчителни:')
            ->assertSee('Общо:')
            ->assertSee('RAM &lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
            ->assertDontSee('RAM <script>alert(1)</script>', escape: false);

        $this->assertSame($before, $this->protectedSnapshot());
    }

    public function test_repeated_evaluation_and_manual_category_change_do_not_create_or_delete_values(): void
    {
        $firstCategory = Category::factory()->create();
        $secondCategory = Category::factory()->create();
        $firstAttribute = $this->attribute('RAM', ProductAttribute::TYPE_TEXT);
        $secondAttribute = $this->attribute('Цвят', ProductAttribute::TYPE_TEXT);
        $this->assign($firstCategory, $firstAttribute, required: true);
        $this->assign($secondCategory, $secondAttribute, required: true);
        $product = Product::factory()->create([
            'category_id' => $firstCategory->id,
            'workflow_status' => Product::WORKFLOW_APPROVED,
        ]);
        $existingValue = $this->textValue($product, $firstAttribute, '16 GB');
        $service = app(ProductSpecificationQualityService::class);
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $first = $service->evaluate($product);
        $queriesAfterFirstEvaluation = $queryCount;
        $second = $service->evaluate($product);

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(8, $queriesAfterFirstEvaluation);
        $this->assertSame($queriesAfterFirstEvaluation, $queryCount);
        $product->update(['category_id' => $secondCategory->id]);
        $before = $this->protectedSnapshot();
        $freshResult = app(ProductSpecificationQualityService::class)->evaluate($product->fresh());

        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, $freshResult->status);
        $this->assertSame(['Цвят'], $freshResult->missingRequiredAttributeLabels());
        $this->assertDatabaseHas('product_attribute_values', ['id' => $existingValue->id, 'value_text' => '16 GB']);
        $this->assertSame(Product::WORKFLOW_APPROVED, $product->fresh()->workflow_status);
        $this->assertSame($before, $this->protectedSnapshot());
    }

    private function assign(
        Category $category,
        ProductAttribute $attribute,
        bool $required,
        int $order = 0,
    ): CategoryProductAttribute {
        return CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_required' => $required,
            'is_visible_on_product' => ! $required,
            'sort_order' => $order,
        ]);
    }

    private function attribute(string $label, string $type): ProductAttribute
    {
        return ProductAttribute::factory()->create([
            'code' => str($label)->ascii()->slug('_')->append('_', fake()->unique()->numberBetween(1000, 9999))->toString(),
            'name' => $label,
            'name_bg' => $label,
            'type' => $type,
            'is_active' => true,
            'is_required' => false,
            'is_required_by_default' => false,
            'is_visible_on_product' => false,
            'is_filterable' => false,
            'is_comparable' => false,
        ]);
    }

    private function textValue(Product $product, ProductAttribute $attribute, string $value): ProductAttributeValue
    {
        return ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'value_text' => $value,
            'custom_value' => $value,
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function protectedSnapshot(): array
    {
        $tables = [
            'products',
            'supplier_products',
            'product_supplier_offers',
            'categories',
            'category_product_attributes',
            'product_attributes',
            'product_attribute_values',
            'product_images',
            'product_quality_flags',
            'product_quality_flag_assignments',
            'brands',
            'suppliers',
            'users',
            'roles',
            'permissions',
        ];

        return [
            'counts' => collect($tables)->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->count(),
            ])->all(),
            'products' => Product::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'categories' => Category::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'assignments' => CategoryProductAttribute::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'values' => ProductAttributeValue::query()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }
}
