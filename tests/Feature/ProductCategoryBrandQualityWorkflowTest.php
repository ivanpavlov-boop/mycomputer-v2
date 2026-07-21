<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductDataQualityQueue\Pages\ListProductDataQualityQueue;
use App\Filament\Resources\ProductDataQualityQueue\Widgets\ProductDataQualityQueueStats;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Products\ProductCategoryBrandQualityResult;
use App\Services\Products\ProductCategoryBrandQualityService;
use App\Services\Products\ProductDataQualityScanner;
use App\Services\Products\ProductDataQualitySummaryResult;
use App\Services\Products\ProductDataQualitySummaryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCategoryBrandQualityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_complete_state_uses_real_category_hierarchy_and_brand(): void
    {
        $parent = Category::factory()->create(['name' => 'Компютри']);
        $category = Category::factory()->create(['name' => 'Лаптопи', 'parent_id' => $parent->id]);
        $brand = Brand::factory()->create(['name' => 'Lenovo']);
        $product = $this->queueProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);

        $result = app(ProductCategoryBrandQualityService::class)->evaluate($product);

        $this->assertSame(ProductCategoryBrandQualityResult::STATE_COMPLETE, $result->state);
        $this->assertSame('Категорията и марката са попълнени', $result->stateLabel);
        $this->assertSame('success', $result->stateColor);
        $this->assertSame('Компютри › Лаптопи', $result->categoryPath);
        $this->assertSame('Lenovo', $result->brandLabel);
        $this->assertSame([], $result->warnings);
    }

    public function test_missing_category_state_keeps_brand_visible(): void
    {
        $brand = Brand::factory()->create(['name' => 'Acer']);
        $product = $this->queueProduct(['category_id' => null, 'brand_id' => $brand->id]);

        $result = app(ProductCategoryBrandQualityService::class)->evaluate($product);

        $this->assertSame(ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY, $result->state);
        $this->assertSame('Липсва категория', $result->stateLabel);
        $this->assertSame('danger', $result->stateColor);
        $this->assertSame('Липсва', $result->categoryDisplayLabel);
        $this->assertSame('Acer', $result->brandDisplayLabel);
    }

    public function test_missing_brand_state_keeps_category_visible(): void
    {
        $category = Category::factory()->create(['name' => 'Монитори']);
        $product = $this->queueProduct(['category_id' => $category->id, 'brand_id' => null]);

        $result = app(ProductCategoryBrandQualityService::class)->evaluate($product);

        $this->assertSame(ProductCategoryBrandQualityResult::STATE_MISSING_BRAND, $result->state);
        $this->assertSame('Липсва марка', $result->stateLabel);
        $this->assertSame('danger', $result->stateColor);
        $this->assertSame('Монитори', $result->categoryDisplayLabel);
        $this->assertSame('Липсва', $result->brandDisplayLabel);
    }

    public function test_missing_both_and_unknown_states_have_safe_labels(): void
    {
        $product = $this->queueProduct(['category_id' => null, 'brand_id' => null]);
        $result = app(ProductCategoryBrandQualityService::class)->evaluate($product);

        $this->assertSame(ProductCategoryBrandQualityResult::STATE_MISSING_BOTH, $result->state);
        $this->assertSame('Липсват категория и марка', $result->stateLabel);
        $this->assertSame('danger', $result->stateColor);
        $this->assertSame('Неизвестно', ProductCategoryBrandQualityResult::labelFor('unexpected'));
        $this->assertSame('gray', ProductCategoryBrandQualityResult::colorFor('unexpected'));
    }

    public function test_assigned_inactive_and_archived_records_are_warnings_without_changing_assignment_state(): void
    {
        $category = Category::factory()->create(['name' => 'Спряна категория', 'is_active' => false]);
        $brand = Brand::factory()->create(['name' => 'Архивирана марка']);
        $product = $this->queueProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $brand->delete();

        $result = app(ProductCategoryBrandQualityService::class)->evaluate($product->fresh());

        $this->assertSame(ProductCategoryBrandQualityResult::STATE_COMPLETE, $result->state);
        $this->assertSame('Неактивна категория', $result->categoryWarning());
        $this->assertSame('Архивирана марка', $result->brandWarning());
        $this->assertSame($category->id, $product->fresh()->category_id);
        $this->assertSame($brand->id, $product->fresh()->brand_id);
    }

    public function test_queue_presents_category_brand_combined_state_hierarchy_and_safe_edit_navigation(): void
    {
        $this->actingAsRole(User::ROLE_PRODUCT_EDITOR);
        $parent = Category::factory()->create(['name' => 'Компютри']);
        $category = Category::factory()->create(['name' => 'Лаптопи', 'parent_id' => $parent->id]);
        $brand = Brand::factory()->create(['name' => 'HP']);
        $complete = $this->queueProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $missing = $this->queueProduct(['category_id' => null, 'brand_id' => null]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$complete, $missing])
            ->assertTableColumnStateSet('category.name', 'Компютри › Лаптопи', $complete)
            ->assertTableColumnStateSet('brand.name', 'HP', $complete)
            ->assertTableColumnStateSet('category_brand_quality', 'Категорията и марката са попълнени', $complete)
            ->assertTableColumnStateSet('category.name', 'Липсва', $missing)
            ->assertTableColumnStateSet('brand.name', 'Липсва', $missing)
            ->assertTableColumnStateSet('category_brand_quality', 'Липсват категория и марка', $missing)
            ->assertTableActionHasUrl('editProduct', ProductResource::getUrl('edit', ['record' => $complete]), $complete)
            ->assertTableActionDoesNotExist('assignCategory', null, $complete)
            ->assertTableActionDoesNotExist('assignBrand', null, $complete);
    }

    public function test_queue_state_filters_are_exact_non_overlapping_and_preserve_issue_filter_behavior(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $complete = $this->queueProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $missingCategory = $this->queueProduct(['category_id' => null, 'brand_id' => $brand->id]);
        $missingBrand = $this->queueProduct(['category_id' => $category->id, 'brand_id' => null]);
        $missingBoth = $this->queueProduct(['category_id' => null, 'brand_id' => null]);

        $records = [$complete, $missingCategory, $missingBrand, $missingBoth];
        Livewire::test(ListProductDataQualityQueue::class)->assertCanSeeTableRecords($records);

        $this->assertStateFilterShows(ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY, $missingCategory, [$complete, $missingBrand, $missingBoth]);
        $this->assertStateFilterShows(ProductCategoryBrandQualityResult::STATE_MISSING_BRAND, $missingBrand, [$complete, $missingCategory, $missingBoth]);
        $this->assertStateFilterShows(ProductCategoryBrandQualityResult::STATE_MISSING_BOTH, $missingBoth, [$complete, $missingCategory, $missingBrand]);
        $this->assertStateFilterShows(ProductCategoryBrandQualityResult::STATE_COMPLETE, $complete, [$missingCategory, $missingBrand, $missingBoth]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_CATEGORY)
            ->assertCanSeeTableRecords([$missingCategory, $missingBoth])
            ->assertCanNotSeeTableRecords([$complete, $missingBrand]);
    }

    public function test_specific_category_and_brand_filters_are_searchable_composable_and_include_archived_assignments(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $category = Category::factory()->create(['name' => 'Принтери']);
        $otherCategory = Category::factory()->create(['name' => 'Скенери']);
        $brand = Brand::factory()->create(['name' => 'Brother']);
        $otherBrand = Brand::factory()->create(['name' => 'Canon']);
        $target = $this->queueProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $sameCategory = $this->queueProduct(['category_id' => $category->id, 'brand_id' => $otherBrand->id]);
        $other = $this->queueProduct(['category_id' => $otherCategory->id, 'brand_id' => $otherBrand->id]);
        $archived = $this->queueProduct(['category_id' => $otherCategory->id, 'brand_id' => $brand->id]);
        $otherCategory->delete();

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertTableFilterExists('category', fn ($filter): bool => $filter->getLabel() === 'Конкретна категория')
            ->assertTableFilterExists('brand', fn ($filter): bool => $filter->getLabel() === 'Конкретна марка')
            ->filterTable('category', $category->id)
            ->assertCanSeeTableRecords([$target, $sameCategory])
            ->assertCanNotSeeTableRecords([$other, $archived]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('brand', $brand->id)
            ->filterTable('category_brand_state', ProductCategoryBrandQualityResult::STATE_COMPLETE)
            ->assertCanSeeTableRecords([$target, $archived])
            ->assertCanNotSeeTableRecords([$sameCategory, $other]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('category', $otherCategory->id)
            ->assertCanSeeTableRecords([$other, $archived])
            ->assertCanNotSeeTableRecords([$target, $sameCategory]);
    }

    public function test_unfiltered_queue_scope_and_soft_deleted_product_exclusion_are_unchanged(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $queueProduct = $this->queueProduct();
        $completeOutsideQueue = $this->queueProduct([], withImage: true);
        $deleted = $this->queueProduct();
        $deleted->delete();

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$queueProduct])
            ->assertCanNotSeeTableRecords([$completeOutsideQueue, $deleted]);
    }

    public function test_queue_overview_counts_use_the_same_queue_scope(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $this->queueProduct(['category_id' => null, 'brand_id' => $brand->id]);
        $this->queueProduct(['category_id' => $category->id, 'brand_id' => null]);
        $this->queueProduct(['category_id' => null, 'brand_id' => null]);
        $this->queueProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $this->queueProduct([], withImage: true);

        $scanner = app(ProductDataQualityScanner::class);
        $counts = app(ProductCategoryBrandQualityService::class)
            ->countsFor($scanner->applyQueueScope(Product::query()));

        $this->assertSame([
            ProductCategoryBrandQualityResult::STATE_MISSING_CATEGORY => 1,
            ProductCategoryBrandQualityResult::STATE_MISSING_BRAND => 1,
            ProductCategoryBrandQualityResult::STATE_MISSING_BOTH => 1,
            ProductCategoryBrandQualityResult::STATE_COMPLETE => 1,
        ], $counts);

        Livewire::test(ProductDataQualityQueueStats::class)
            ->assertSee('Липсва категория')
            ->assertSee('Липсва марка')
            ->assertSee('Липсват и двете')
            ->assertSee('Попълнени категория и марка');
    }

    public function test_product_edit_summary_shows_state_next_steps_and_escapes_catalog_labels(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $unsafeParent = '<script>alert("parent")</script> & Компютри';
        $unsafeCategory = '<img src=x onerror=alert("category")> Лаптопи';
        $unsafeBrand = '<svg onload=alert("brand")> Марка';
        $parent = Category::factory()->create(['name' => $unsafeParent]);
        $category = Category::factory()->create(['name' => $unsafeCategory, 'parent_id' => $parent->id]);
        $brand = Brand::factory()->create(['name' => $unsafeBrand]);
        $product = $this->queueProduct(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $summary = app(ProductDataQualitySummaryService::class)->summarize($product);
        $html = view('filament.products.data-quality-summary', compact('summary'))->render();

        $this->assertSame(ProductCategoryBrandQualityResult::STATE_COMPLETE, $summary->categoryBrandQuality->state);
        $this->assertStringContainsString(e($unsafeParent), $html);
        $this->assertStringContainsString(e($unsafeCategory), $html);
        $this->assertStringContainsString(e($unsafeBrand), $html);
        $this->assertStringNotContainsString($unsafeParent, $html);
        $this->assertStringNotContainsString($unsafeCategory, $html);
        $this->assertStringNotContainsString($unsafeBrand, $html);

        $missing = $this->queueProduct(['category_id' => null, 'brand_id' => null]);
        $missingSummary = app(ProductDataQualitySummaryService::class)->summarize($missing);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_CRITICAL, $missingSummary->overallStatus);
        $this->assertContains('Задайте категория', $missingSummary->nextSteps);
        $this->assertContains('Задайте марка', $missingSummary->nextSteps);
        $this->get(ProductResource::getUrl('edit', ['record' => $missing]))
            ->assertOk()
            ->assertSee('Категория и марка')
            ->assertSee('Липсват категория и марка');
    }

    public function test_queue_filters_counts_and_summary_are_bounded_and_do_not_mutate_protected_data(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $products = collect(range(1, 12))->map(fn (): Product => $this->queueProduct([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]));
        $product = $products->first();
        SupplierProduct::query()->create([
            'supplier_id' => $product->supplier_id,
            'supplier_sku' => 'CATEGORY-BRAND-QUALITY-STAGED',
            'name' => 'Untouched supplier staging row',
            'currency' => 'EUR',
            'raw_data' => ['quality' => 'untouched'],
            'payload_hash' => 'category-brand-quality-staged-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $beforeCounts = $this->protectedTableCounts();
        $beforeProducts = Product::query()->orderBy('id')->get()->map->getAttributes()->all();
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListProductDataQualityQueue::class);

        $queueQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        Livewire::test(ListProductDataQualityQueue::class)
            ->set('tableRecordsPerPage', 25)
            ->filterTable('category_brand_state', ProductCategoryBrandQualityResult::STATE_COMPLETE)
            ->filterTable('category', $category->id)
            ->filterTable('brand', $brand->id)
            ->assertCanSeeTableRecords($products);

        Livewire::test(ProductDataQualityQueueStats::class)->assertSee('Попълнени категория и марка');
        app(ProductDataQualitySummaryService::class)->summarize($product->fresh());

        $this->assertLessThanOrEqual(25, $queueQueryCount, "Queue used {$queueQueryCount} queries for 12 Products.");
        $this->assertSame($beforeCounts, $this->protectedTableCounts());
        $this->assertSame($beforeProducts, Product::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_viewer_auditor_keeps_read_only_queue_access_without_edit_actions(): void
    {
        $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);
        $product = $this->queueProduct(['category_id' => null, 'brand_id' => null]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableActionHidden('editProduct', $product)
            ->assertTableActionHidden('openProduct', $product)
            ->assertTableActionDoesNotExist('assignCategory', null, $product)
            ->assertTableActionDoesNotExist('assignBrand', null, $product);

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
    }

    /**
     * @param  array<int, Product>  $hidden
     */
    private function assertStateFilterShows(string $state, Product $visible, array $hidden): void
    {
        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('category_brand_state', $state)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords($hidden);
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
    private function queueProduct(array $overrides = [], bool $withImage = false): Product
    {
        $product = Product::factory()->create(array_merge([
            'name' => 'Продукт за проверка на категория и марка',
            'sku' => fake()->unique()->bothify('CBQ-####??'),
            'ean' => fake()->unique()->numerify('#############'),
            'short_description' => str_repeat('Подробно кратко описание. ', 2),
            'description' => str_repeat('Подробно продуктово описание за проверка на качеството. ', 3),
            'meta_title' => 'Попълнено SEO заглавие',
            'meta_description' => 'Попълнено SEO описание за продукта.',
            'name_translations' => ['en' => 'Category and brand quality product'],
            'description_translations' => ['en' => 'Detailed English product description.'],
            'meta_title_translations' => ['en' => 'Category and brand quality product'],
            'specifications' => ['CPU' => 'Intel Core i7'],
        ], $overrides));

        if ($withImage) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'products/category-brand-quality.jpg',
                'alt_text' => $product->name,
                'sort_order' => 1,
                'is_primary' => true,
            ]);
        }

        return $product;
    }

    /**
     * @return array<string, int>
     */
    private function protectedTableCounts(): array
    {
        return collect([
            'products',
            'supplier_products',
            'categories',
            'brands',
            'product_images',
            'product_attribute_values',
            'category_product_attributes',
            'product_quality_flags',
            'product_quality_flag_assignments',
            'product_supplier_offers',
            'users',
            'roles',
            'permissions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
