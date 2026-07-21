<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Products\ProductCategoryBrandQualityResult;
use App\Services\Products\ProductDataQualityScanner;
use App\Services\Products\ProductDataQualitySummaryResult;
use App\Services\Products\ProductDataQualitySummaryService;
use App\Services\Products\ProductImageQualityResult;
use App\Services\Products\ProductSeoDescriptionQualityResult;
use App\Services\Products\ProductSpecificationQualityResult;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDataQualitySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unknown_overall_status_uses_safe_fallback_presentation(): void
    {
        $specification = new ProductSpecificationQualityResult(
            status: 'unknown',
            expectedCount: 0,
            filledCount: 0,
            missingCount: 0,
            percentageComplete: 0,
            expectedAttributes: collect(),
            filledAttributes: collect(),
            missingAttributes: collect(),
        );
        $result = new ProductDataQualitySummaryResult(
            overallStatus: 'unknown',
            coreIssues: [],
            criticalIssueCount: 0,
            warningIssueCount: 0,
            recommendationIssueCount: 0,
            specificationResult: $specification,
            categoryBrandQuality: new ProductCategoryBrandQualityResult(
                state: ProductCategoryBrandQualityResult::STATE_COMPLETE,
                categoryLabel: 'Категория',
                categoryPath: 'Категория',
                brandLabel: 'Марка',
                categoryInactive: false,
                categoryArchived: false,
                brandInactive: false,
                brandArchived: false,
                warnings: [],
            ),
            imageQuality: new ProductImageQualityResult(
                state: ProductImageQualityResult::STATE_COMPLETE,
                totalImageCount: 1,
                eligibleImageCount: 1,
                primaryImageCount: 1,
                imagesWithAltText: 1,
                imagesMissingAltText: 0,
                issues: [],
                nextSteps: [],
                altTextSamples: ['Продуктова снимка'],
            ),
            seoDescriptionQuality: new ProductSeoDescriptionQualityResult(
                state: ProductSeoDescriptionQualityResult::STATE_COMPLETE,
                seoTitlePresent: true,
                seoDescriptionPresent: true,
                seoCompletedCount: 2,
                seoExpectedCount: 2,
                shortDescriptionPresent: true,
                fullDescriptionPresent: true,
                descriptionCompletedCount: 2,
                descriptionExpectedCount: 2,
                weakDescription: false,
                englishCompletedCount: 3,
                englishExpectedCount: 3,
                missingEnglishFieldLabels: [],
                issues: [],
                nextSteps: [],
                seoTitleLength: 10,
                seoDescriptionLength: 20,
                shortDescriptionLength: 30,
                fullDescriptionLength: 80,
            ),
            manualFlags: [],
            activeManualQualityFlagLabels: [],
            totalActionableIssueCount: 0,
            nextSteps: [],
        );

        $this->assertSame('Неизвестно', $result->overallLabel);
        $this->assertSame('gray', $result->statusColor);
    }

    public function test_complete_product_has_good_unified_quality_status(): void
    {
        $product = $this->qualityReadyProduct();
        $attribute = $this->assignSpecification($product, 'Оперативна памет', required: true);
        $this->fillSpecification($product, $attribute, '16 GB');

        $result = app(ProductDataQualitySummaryService::class)->summarize($product);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_GOOD, $result->overallStatus);
        $this->assertSame('Добро', $result->overallLabel);
        $this->assertSame('success', $result->statusColor);
        $this->assertSame([], $result->coreIssues);
        $this->assertSame(ProductSpecificationQualityResult::STATUS_GOOD, $result->specificationResult->status);
        $this->assertSame('1/1 (100%)', $result->specificationResult->scoreLabel());
        $this->assertSame([], $result->manualFlags);
        $this->assertSame(0, $result->totalActionableIssueCount);
        $this->assertSame([], $result->nextSteps);
    }

    public function test_missing_core_catalog_data_is_critical(): void
    {
        $product = $this->qualityReadyProduct([
            'category_id' => null,
            'brand_id' => null,
            'meta_title' => null,
            'meta_description' => '',
        ], withImage: false);

        $result = app(ProductDataQualitySummaryService::class)->summarize($product);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_CRITICAL, $result->overallStatus);
        $this->assertSame('Критични липси', $result->overallLabel);
        $this->assertSame('danger', $result->statusColor);
        $this->assertSame([
            ProductDataQualityScanner::ISSUE_MISSING_IMAGE,
            ProductDataQualityScanner::ISSUE_MISSING_CATEGORY,
            ProductDataQualityScanner::ISSUE_MISSING_BRAND,
            ProductDataQualityScanner::ISSUE_MISSING_SEO,
        ], collect($result->coreIssues)->pluck('code')->all());
        $this->assertSame(['high'], collect($result->coreIssues)->pluck('severity')->unique()->values()->all());
        $this->assertGreaterThanOrEqual(4, $result->criticalIssueCount);
        $this->assertContains('Задайте категория', $result->nextSteps);
        $this->assertContains('Задайте марка', $result->nextSteps);
        $this->assertContains('Добавете продуктова снимка', $result->nextSteps);
    }

    public function test_weak_descriptions_and_incomplete_recommended_specs_need_attention(): void
    {
        $product = $this->qualityReadyProduct([
            'short_description' => 'Кратко',
            'description' => 'Недостатъчно',
        ]);
        $this->assignSpecification($product, 'Препоръчителна характеристика', required: false);

        $result = app(ProductDataQualitySummaryService::class)->summarize($product);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_NEEDS_ATTENTION, $result->overallStatus);
        $this->assertSame(ProductSpecificationQualityResult::STATUS_NEEDS_DATA, $result->specificationResult->status);
        $this->assertSame('warning', collect($result->coreIssues)->firstWhere('code', ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION)['level']);
        $this->assertSame(2, $result->warningIssueCount);
        $this->assertContains('Допълнете краткото и пълното описание', $result->nextSteps);
        $this->assertContains('Допълнете препоръчителните характеристики', $result->nextSteps);
    }

    public function test_missing_required_specifications_are_critical(): void
    {
        $product = $this->qualityReadyProduct();
        $this->assignSpecification($product, 'Процесор', required: true);

        $result = app(ProductDataQualitySummaryService::class)->summarize($product);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_CRITICAL, $result->overallStatus);
        $this->assertSame(ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED, $result->specificationResult->status);
        $this->assertSame(['Процесор'], $result->specificationResult->missingAttributeLabels());
        $this->assertSame(1, $result->criticalIssueCount);
    }

    public function test_missing_category_template_is_a_read_only_warning(): void
    {
        $product = $this->qualityReadyProduct();
        $before = $this->protectedTableCounts();

        $result = app(ProductDataQualitySummaryService::class)->summarize($product);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_NEEDS_ATTENTION, $result->overallStatus);
        $this->assertSame(ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE, $result->specificationResult->status);
        $this->assertNotContains(ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES, collect($result->coreIssues)->pluck('code')->all());
        $this->assertSame(1, $result->warningIssueCount);
        $this->assertSame($before, $this->protectedTableCounts());
    }

    public function test_active_manual_flags_affect_status_without_modifying_assignments(): void
    {
        $product = $this->qualityReadyProduct();
        $attribute = $this->assignSpecification($product, 'Памет', required: true);
        $this->fillSpecification($product, $attribute, '32 GB');
        $high = $this->assignManualFlag($product, 'Проверете техническите данни', ProductQualityFlag::SEVERITY_HIGH, User::ROLE_PRODUCT_EDITOR);
        $low = $this->assignManualFlag($product, 'Допълнителна препоръка', ProductQualityFlag::SEVERITY_LOW, User::ROLE_CATALOG_MANAGER);
        $before = ProductQualityFlagAssignment::query()->orderBy('id')->get()->map->getAttributes()->all();

        $result = app(ProductDataQualitySummaryService::class)->summarize($product);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_CRITICAL, $result->overallStatus);
        $this->assertSame([$high->label_bg, $low->label_bg], $result->activeManualQualityFlagLabels);
        $this->assertSame('Висока', $result->manualFlags[0]['severity_label']);
        $this->assertSame('Редактор на продукти', $result->manualFlags[0]['responsible_role_label']);
        $this->assertContains('Прегледайте активните флагове за качество', $result->nextSteps);
        $this->assertSame($before, ProductQualityFlagAssignment::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_missing_english_and_ean_are_non_blocking_recommendations(): void
    {
        $product = $this->qualityReadyProduct([
            'ean' => null,
            'name_translations' => null,
            'description_translations' => null,
            'meta_title_translations' => null,
            'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
        ]);
        $attribute = $this->assignSpecification($product, 'Дисплей', required: true);
        $this->fillSpecification($product, $attribute, '15.6 инча');

        $result = app(ProductDataQualitySummaryService::class)->summarize($product);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_NEEDS_ATTENTION, $result->overallStatus);
        $this->assertSame(0, $result->criticalIssueCount);
        $this->assertSame(0, $result->warningIssueCount);
        $this->assertSame(2, $result->recommendationIssueCount);
        $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $product->fresh()->workflow_status);
        $this->assertContains('Добавете английска локализация', $result->nextSteps);
        $this->assertContains('Добавете EAN, когато е приложимо', $result->nextSteps);
    }

    public function test_summary_view_escapes_attribute_and_manual_flag_labels(): void
    {
        $product = $this->qualityReadyProduct();
        $unsafeAttribute = '<script>alert("spec")</script> & "RAM"';
        $unsafeFlag = '<img src=x onerror=alert("flag")> & review';
        $this->assignSpecification($product, $unsafeAttribute, required: true);
        $this->assignManualFlag($product, $unsafeFlag, ProductQualityFlag::SEVERITY_MEDIUM, User::ROLE_PRODUCT_EDITOR);

        $summary = app(ProductDataQualitySummaryService::class)->summarize($product);
        $html = view('filament.products.data-quality-summary', compact('summary'))->render();

        $this->assertStringContainsString(e($unsafeAttribute), $html);
        $this->assertStringContainsString(e($unsafeFlag), $html);
        $this->assertStringNotContainsString($unsafeAttribute, $html);
        $this->assertStringNotContainsString($unsafeFlag, $html);
    }

    public function test_product_edit_renders_summary_once_without_dehydrated_state_and_create_omits_it(): void
    {
        $this->actingAsSuperAdmin();
        $product = $this->qualityReadyProduct();
        $this->assignSpecification($product, 'RAM', required: true);

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Качество на продуктовите данни')
            ->assertSee('Обобщение на липсващи или непълни данни')
            ->assertSee('Критични липси')
            ->assertSee('Липсват важни характеристики')
            ->assertSee('0/1 · 0%')
            ->assertSee('Няма активни флагове')
            ->assertSee('Попълнете задължителните характеристики');

        $this->assertArrayNotHasKey('product_data_quality_summary', $component->get('data'));

        $html = $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->getContent();
        $mainPosition = strpos($html, 'Основна информация');
        $summaryPosition = strpos($html, 'Качество на продуктовите данни');
        $workflowPosition = strpos($html, 'Работен процес на продукта');

        $this->assertIsInt($mainPosition);
        $this->assertIsInt($summaryPosition);
        $this->assertIsInt($workflowPosition);
        $this->assertLessThan($summaryPosition, $mainPosition);
        $this->assertLessThan($workflowPosition, $summaryPosition);

        $this->get(ProductResource::getUrl('create'))
            ->assertOk()
            ->assertDontSee('Качество на продуктовите данни');
    }

    public function test_rendering_summary_is_bounded_and_does_not_mutate_protected_tables_or_timestamps(): void
    {
        $this->actingAsSuperAdmin();
        $product = $this->qualityReadyProduct();
        $this->assignSpecification($product, 'Памет & съхранение', required: true);
        $this->assignManualFlag($product, 'Преглед на съдържанието', ProductQualityFlag::SEVERITY_MEDIUM, User::ROLE_PRODUCT_EDITOR);
        SupplierProduct::query()->create([
            'supplier_id' => $product->supplier_id,
            'supplier_sku' => 'QUALITY-SUMMARY-STAGED-001',
            'name' => 'Staged product untouched by summary',
            'currency' => 'EUR',
            'raw_data' => ['quality' => 'untouched'],
            'payload_hash' => 'quality-summary-staged-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $beforeCounts = $this->protectedTableCounts();
        $beforeProduct = $product->fresh()->getAttributes();
        $beforeAssignments = ProductQualityFlagAssignment::query()->orderBy('id')->get()->map->getAttributes()->all();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(ProductDataQualitySummaryService::class)->summarize($product->fresh());
        $summaryQueryCount = count($queries);

        $this->assertLessThanOrEqual(16, $summaryQueryCount, "Summary used {$summaryQueryCount} queries.");
        $this->assertSame($beforeCounts, $this->protectedTableCounts());
        $this->assertSame($beforeProduct, $product->fresh()->getAttributes());
        $this->assertSame($beforeAssignments, ProductQualityFlagAssignment::query()->orderBy('id')->get()->map->getAttributes()->all());

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('Качество на продуктовите данни');

        $this->assertSame($beforeCounts, $this->protectedTableCounts());
        $this->assertSame($beforeProduct, $product->fresh()->getAttributes());
        $this->assertSame($beforeAssignments, ProductQualityFlagAssignment::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_summary_does_not_block_an_unrelated_product_save_or_change_authorization(): void
    {
        $this->actingAsSuperAdmin();
        $product = $this->qualityReadyProduct(withImage: false);
        $originalWorkflow = $product->workflow_status;

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.name', 'Обновено име без блокиране')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Обновено име без блокиране', $product->fresh()->name);
        $this->assertSame($originalWorkflow, $product->fresh()->workflow_status);

        $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);
        $this->assertFalse(ProductResource::canViewAny());
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
    }

    private function actingAsSuperAdmin(): User
    {
        return $this->actingAsRole(User::ROLE_SUPER_ADMIN);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function qualityReadyProduct(array $overrides = [], bool $withImage = true): Product
    {
        $product = Product::factory()->create(array_merge([
            'short_description' => str_repeat('Подробно кратко описание за проверка на качеството. ', 2),
            'description' => str_repeat('Подробно продуктово описание за проверка на качеството и съдържанието. ', 3),
            'meta_title' => 'Качествено SEO заглавие',
            'meta_description' => 'Подробно SEO описание за продукт с попълнени основни данни.',
            'name_translations' => ['en' => 'Quality ready product'],
            'description_translations' => ['en' => 'Detailed English product description.'],
            'meta_title_translations' => ['en' => 'Quality ready product SEO'],
            'ean' => fake()->unique()->ean13(),
        ], $overrides));

        if ($withImage) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'products/quality-summary-'.$product->id.'.jpg',
                'alt_text' => 'Продуктова снимка',
                'sort_order' => 0,
                'is_primary' => true,
            ]);
        }

        return $product;
    }

    private function assignSpecification(Product $product, string $label, bool $required): ProductAttribute
    {
        $attribute = ProductAttribute::factory()->create([
            'code' => 'quality_summary_'.fake()->unique()->numberBetween(1000, 999999),
            'name' => $label,
            'name_bg' => $label,
            'type' => ProductAttribute::TYPE_TEXT,
            'is_active' => true,
            'is_required_by_default' => false,
        ]);

        CategoryProductAttribute::factory()->create([
            'category_id' => $product->category_id,
            'product_attribute_id' => $attribute->id,
            'is_required' => $required,
            'is_visible_on_product' => ! $required,
            'sort_order' => 1,
        ]);

        return $attribute;
    }

    private function fillSpecification(Product $product, ProductAttribute $attribute, string $value): void
    {
        ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'value_text' => $value,
            'custom_value' => $value,
        ]);
    }

    private function assignManualFlag(Product $product, string $label, string $severity, string $role): ProductQualityFlag
    {
        $flag = ProductQualityFlag::query()->create([
            'code' => 'quality_summary_flag_'.fake()->unique()->numberBetween(1000, 999999),
            'label_bg' => $label,
            'label_en' => 'Quality summary flag',
            'severity' => $severity,
            'responsible_role' => $role,
            'type' => ProductQualityFlag::TYPE_DATA,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductQualityFlagAssignment::query()->create([
            'product_id' => $product->id,
            'product_quality_flag_id' => $flag->id,
            'status' => ProductQualityFlagAssignment::STATUS_ACTIVE,
        ]);

        return $flag;
    }

    /**
     * @return array<string, int>
     */
    private function protectedTableCounts(): array
    {
        return collect([
            'products',
            'supplier_products',
            'product_attribute_values',
            'category_product_attributes',
            'product_quality_flags',
            'product_quality_flag_assignments',
            'product_images',
            'categories',
            'brands',
            'suppliers',
            'users',
            'roles',
            'permissions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
