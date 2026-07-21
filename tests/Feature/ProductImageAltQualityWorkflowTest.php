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
use App\Services\Products\ProductDataQualityScanner;
use App\Services\Products\ProductDataQualitySummaryResult;
use App\Services\Products\ProductDataQualitySummaryService;
use App\Services\Products\ProductImageQualityResult;
use App\Services\Products\ProductImageQualityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductImageAltQualityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->category = Category::factory()->create(['name' => 'Лаптопи']);
        $this->brand = Brand::factory()->create(['name' => 'Lenovo']);
    }

    public function test_complete_image_metadata_has_success_state_and_full_alt_coverage(): void
    {
        $product = $this->qualityProduct();
        $this->addImage($product, 'Основна снимка', primary: true, order: 1);
        $this->addImage($product, 'Изглед отстрани', order: 2);

        $result = app(ProductImageQualityService::class)->evaluate($product);

        $this->assertSame(ProductImageQualityResult::STATE_COMPLETE, $result->state);
        $this->assertSame('Снимките и ALT текстовете са попълнени', $result->stateLabel);
        $this->assertSame('success', $result->stateColor);
        $this->assertSame(2, $result->eligibleImageCount);
        $this->assertSame(1, $result->primaryImageCount);
        $this->assertSame('Зададена', $result->primaryStatusLabel);
        $this->assertSame('2/2 · 100%', $result->altCoverageLabel);
        $this->assertSame(100, $result->altCompletionPercentage);
        $this->assertSame([], $result->issues);
        $this->assertSame('Неизвестно', ProductImageQualityResult::labelFor('unexpected'));
        $this->assertSame('gray', ProductImageQualityResult::colorFor('unexpected'));
    }

    public function test_no_images_is_critical_zero_safe_and_creates_nothing(): void
    {
        $product = $this->qualityProduct();
        $before = ProductImage::query()->count();

        $result = app(ProductImageQualityService::class)->evaluate($product);

        $this->assertSame(ProductImageQualityResult::STATE_NO_IMAGES, $result->state);
        $this->assertSame('danger', $result->stateColor);
        $this->assertSame('0/0', $result->altCoverageLabel);
        $this->assertSame(1, $result->criticalIssueCount);
        $this->assertContains('Добавете продуктова снимка', $result->nextSteps);
        $this->assertSame($before, ProductImage::query()->count());
    }

    public function test_missing_primary_is_warning_and_does_not_select_an_image(): void
    {
        $product = $this->qualityProduct();
        $image = $this->addImage($product, 'Продуктова снимка');

        $result = app(ProductImageQualityService::class)->evaluate($product);

        $this->assertSame(ProductImageQualityResult::STATE_MISSING_PRIMARY, $result->state);
        $this->assertSame('warning', $result->stateColor);
        $this->assertSame('Липсва', $result->primaryStatusLabel);
        $this->assertContains('Задайте основна снимка', $result->nextSteps);
        $this->assertFalse((bool) $image->fresh()->is_primary);
    }

    public function test_multiple_primary_is_critical_and_has_priority_over_missing_alt(): void
    {
        $product = $this->qualityProduct();
        $first = $this->addImage($product, null, primary: true, order: 1);
        $second = $this->addImage($product, 'Втора снимка', primary: true, order: 2);

        $result = app(ProductImageQualityService::class)->evaluate($product);

        $this->assertSame(ProductImageQualityResult::STATE_MULTIPLE_PRIMARY, $result->state);
        $this->assertSame('danger', $result->stateColor);
        $this->assertSame(2, $result->primaryImageCount);
        $this->assertSame('Повече от една', $result->primaryStatusLabel);
        $this->assertSame(1, $result->criticalIssueCount);
        $this->assertSame(1, $result->warningIssueCount);
        $this->assertTrue((bool) $first->fresh()->is_primary);
        $this->assertTrue((bool) $second->fresh()->is_primary);
    }

    public function test_all_missing_alt_including_whitespace_is_warning_without_normalization(): void
    {
        $product = $this->qualityProduct();
        $primary = $this->addImage($product, '   ', primary: true, order: 1);
        $secondary = $this->addImage($product, null, order: 2);

        $result = app(ProductImageQualityService::class)->evaluate($product);

        $this->assertSame(ProductImageQualityResult::STATE_MISSING_ALT_ALL, $result->state);
        $this->assertSame('warning', $result->stateColor);
        $this->assertSame('0/2 · 0%', $result->altCoverageLabel);
        $this->assertSame(2, $result->imagesMissingAltText);
        $this->assertSame('   ', $primary->fresh()->alt_text);
        $this->assertNull($secondary->fresh()->alt_text);
    }

    public function test_partial_missing_alt_reports_correct_score(): void
    {
        $product = $this->qualityProduct();
        $this->addImage($product, 'Основна снимка', primary: true, order: 1);
        $this->addImage($product, '', order: 2);
        $this->addImage($product, 'Заден панел', order: 3);

        $result = app(ProductImageQualityService::class)->evaluate($product);

        $this->assertSame(ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL, $result->state);
        $this->assertSame('2/3 · 67%', $result->altCoverageLabel);
        $this->assertSame(67, $result->altCompletionPercentage);
        $this->assertSame(1, $result->imagesMissingAltText);
        $this->assertContains('Допълнете липсващите ALT текстове', $result->nextSteps);
    }

    public function test_service_memoizes_one_result_per_product_instance(): void
    {
        $product = $this->qualityProduct();
        $this->addImage($product, 'Основна снимка', primary: true);
        $product = $product->fresh();
        $service = app(ProductImageQualityService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $service->evaluate($product);
        $queriesAfterFirstEvaluation = count(DB::getQueryLog());
        $second = $service->evaluate($product);
        $queriesAfterSecondEvaluation = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame($queriesAfterFirstEvaluation, $queriesAfterSecondEvaluation);
        $this->assertLessThanOrEqual(1, $queriesAfterFirstEvaluation);
    }

    public function test_queue_presents_compact_image_quality_and_suppresses_remote_image_urls(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $product = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($product, 'Основна снимка', primary: true, order: 1);
        $this->addImage($product, null, order: 2);
        $remote = $this->qualityProduct(['meta_description' => null]);
        $remoteUrl = 'https://supplier.invalid/private-image.jpg';
        $this->addImage($remote, 'Отдалечена снимка', primary: true, path: $remoteUrl);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product, $remote])
            ->assertTableColumnStateSet('image_count', '2 снимки', $product)
            ->assertTableColumnStateSet('primary_image_status', 'Основна снимка: Зададена', $product)
            ->assertTableColumnStateSet('image_alt_coverage', '1/2 · 50%', $product)
            ->assertTableColumnStateSet('image_quality', 'Липсва ALT текст за част от снимките', $product)
            ->assertDontSee($remoteUrl)
            ->assertSee('Качество на снимките')
            ->assertSee('Категория / марка')
            ->assertTableActionDoesNotExist('editImages', null, $product)
            ->assertTableActionDoesNotExist('generateAlt', null, $product)
            ->assertTableBulkActionDoesNotExist('updateImages');
    }

    public function test_image_state_filters_are_exact_prioritized_and_composable(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $noImages = $this->qualityProduct(['meta_description' => null]);
        $multiple = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($multiple, null, primary: true, order: 1);
        $this->addImage($multiple, null, primary: true, order: 2);
        $missingPrimary = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($missingPrimary, 'Снимка');
        $missingAltAll = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($missingAltAll, '  ', primary: true);
        $missingAltPartial = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($missingAltPartial, 'Основна', primary: true, order: 1);
        $this->addImage($missingAltPartial, null, order: 2);
        $complete = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($complete, 'Основна', primary: true);
        $records = [$noImages, $multiple, $missingPrimary, $missingAltAll, $missingAltPartial, $complete];

        Livewire::test(ListProductDataQualityQueue::class)->assertCanSeeTableRecords($records);
        $this->assertImageFilterShows(ProductImageQualityResult::STATE_NO_IMAGES, $noImages, $records);
        $this->assertImageFilterShows(ProductImageQualityResult::STATE_MULTIPLE_PRIMARY, $multiple, $records);
        $this->assertImageFilterShows(ProductImageQualityResult::STATE_MISSING_PRIMARY, $missingPrimary, $records);
        $this->assertImageFilterShows(ProductImageQualityResult::STATE_MISSING_ALT_ALL, $missingAltAll, $records);
        $this->assertImageFilterShows(ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL, $missingAltPartial, $records);
        $this->assertImageFilterShows(ProductImageQualityResult::STATE_COMPLETE, $complete, $records);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('category_brand_state', ProductCategoryBrandQualityResult::STATE_COMPLETE)
            ->filterTable('image_quality_state', ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL)
            ->assertCanSeeTableRecords([$missingAltPartial])
            ->assertCanNotSeeTableRecords([$noImages, $multiple, $missingPrimary, $missingAltAll, $complete]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_IMAGE)
            ->assertCanSeeTableRecords([$noImages])
            ->assertCanNotSeeTableRecords([$multiple, $missingPrimary, $missingAltAll, $missingAltPartial, $complete]);
    }

    public function test_image_statistics_use_the_existing_queue_scope(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $noImages = $this->qualityProduct(['meta_description' => null]);
        $missingPrimary = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($missingPrimary, 'Снимка');
        $missingAlt = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($missingAlt, null, primary: true);
        $multiplePrimary = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($multiplePrimary, null, primary: true, order: 1);
        $this->addImage($multiplePrimary, 'Втора снимка', primary: true, order: 2);
        $complete = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($complete, 'Основна', primary: true);
        $outsideQueue = $this->qualityProduct();
        $this->addImage($outsideQueue, 'Основна', primary: true);

        $scanner = app(ProductDataQualityScanner::class);
        $counts = app(ProductImageQualityService::class)
            ->countsFor($scanner->applyQueueScope(Product::query()));

        $this->assertSame(1, $counts[ProductImageQualityResult::STATE_NO_IMAGES]);
        $this->assertSame(1, $counts[ProductImageQualityResult::STATE_MISSING_PRIMARY]);
        $this->assertSame(1, $counts[ProductImageQualityResult::STATE_MISSING_ALT_ALL]);
        $this->assertSame(1, $counts[ProductImageQualityResult::STATE_MULTIPLE_PRIMARY]);
        $this->assertSame(1, $counts[ProductImageQualityResult::STATE_COMPLETE]);
        $this->assertSame(5, array_sum($counts));
        $this->assertSame(2, app(ProductImageQualityService::class)
            ->countWithMissingAltFor($scanner->applyQueueScope(Product::query())));
        $this->assertNotContains($outsideQueue->id, $scanner->applyQueueScope(Product::query())->pluck('id')->all());

        Livewire::test(ProductDataQualityQueueStats::class)
            ->assertSee('Липсват снимки')
            ->assertSee('Липсва основна снимка')
            ->assertSee('Липсва ALT текст')
            ->assertSee('Снимките са подготвени');
    }

    public function test_product_summary_reuses_image_quality_without_duplicate_missing_image_count_and_escapes_alt(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $noImages = $this->qualityProduct();
        $noImageSummary = app(ProductDataQualitySummaryService::class)->summarize($noImages);

        $this->assertSame(ProductDataQualitySummaryResult::STATUS_CRITICAL, $noImageSummary->overallStatus);
        $this->assertSame(1, $noImageSummary->criticalIssueCount);
        $this->assertSame(ProductImageQualityResult::STATE_NO_IMAGES, $noImageSummary->imageQuality->state);
        $this->assertSame(1, collect($noImageSummary->coreIssues)->where('code', ProductDataQualityScanner::ISSUE_MISSING_IMAGE)->count());
        $this->assertSame(1, collect($noImageSummary->nextSteps)->where(fn (string $step): bool => $step === 'Добавете продуктова снимка')->count());

        $unsafeAlt = '<script>alert("alt")</script> & "снимка"';
        $product = $this->qualityProduct();
        $this->addImage($product, $unsafeAlt, primary: true, order: 1);
        $this->addImage($product, null, order: 2);
        $summary = app(ProductDataQualitySummaryService::class)->summarize($product);
        $html = view('filament.products.data-quality-summary', compact('summary'))->render();

        $this->assertSame(ProductImageQualityResult::STATE_MISSING_ALT_PARTIAL, $summary->imageQuality->state);
        $this->assertSame(ProductCategoryBrandQualityResult::STATE_COMPLETE, $summary->categoryBrandQuality->state);
        $this->assertStringContainsString('Снимки и ALT текст', $html);
        $this->assertStringContainsString('1/2 · 50%', $html);
        $this->assertStringContainsString(e($unsafeAlt), $html);
        $this->assertStringNotContainsString($unsafeAlt, $html);
        $this->assertStringNotContainsString('products/image-quality-', $html);

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('Снимки и ALT текст')
            ->assertSee('Липсва ALT текст за част от снимките');
    }

    public function test_queue_render_is_query_bounded_and_image_workflow_is_zero_mutation(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        Storage::fake('public');
        Http::preventStrayRequests();
        $products = collect(range(1, 12))->map(function (int $index): Product {
            $product = $this->qualityProduct(['meta_description' => null]);
            $this->addImage($product, $index % 2 === 0 ? null : 'ALT '.$index, primary: true);

            return $product;
        });
        $product = $products->first();
        SupplierProduct::query()->create([
            'supplier_id' => $product->supplier_id,
            'supplier_sku' => 'IMAGE-QUALITY-STAGED',
            'name' => 'Untouched staging row',
            'currency' => 'EUR',
            'raw_data' => ['quality' => 'untouched'],
            'payload_hash' => 'image-quality-staged-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $beforeCounts = $this->protectedTableCounts();
        $beforeProducts = Product::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeImages = ProductImage::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeFiles = Storage::disk('public')->allFiles();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListProductDataQualityQueue::class)->set('tableRecordsPerPage', 25);
        $queueQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        app(ProductImageQualityService::class)->evaluate($product->fresh());
        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('image_quality_state', ProductImageQualityResult::STATE_MISSING_ALT_ALL);
        Livewire::test(ProductDataQualityQueueStats::class);
        app(ProductDataQualitySummaryService::class)->summarize($product->fresh());
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk();

        $this->assertLessThanOrEqual(25, $queueQueryCount, "Queue used {$queueQueryCount} queries for 12 Products.");
        $this->assertSame($beforeCounts, $this->protectedTableCounts());
        $this->assertSame($beforeProducts, Product::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($beforeImages, ProductImage::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($beforeFiles, Storage::disk('public')->allFiles());
    }

    public function test_viewer_auditor_remains_read_only_and_super_admin_access_remains_intact(): void
    {
        $product = $this->qualityProduct(['meta_description' => null]);
        $this->addImage($product, null, primary: true);

        $viewer = $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);
        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableActionHidden('editProduct', $product)
            ->assertTableActionHidden('openProduct', $product)
            ->assertTableActionDoesNotExist('editImages', null, $product)
            ->assertTableBulkActionDoesNotExist('updateImages');
        $this->assertTrue($viewer->isActiveAdminAccount());
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();

        $superAdmin = $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $this->assertTrue($superAdmin->isActiveAdminAccount());
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk();
    }

    /**
     * @param  array<int, Product>  $records
     */
    private function assertImageFilterShows(string $state, Product $visible, array $records): void
    {
        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('image_quality_state', $state)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords(array_values(array_filter(
                $records,
                fn (Product $product): bool => ! $product->is($visible),
            )));
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function qualityProduct(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Продукт за проверка на снимки',
            'sku' => fake()->unique()->bothify('IMG-####??'),
            'ean' => fake()->unique()->numerify('#############'),
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'short_description' => str_repeat('Подробно кратко описание. ', 2),
            'description' => str_repeat('Подробно продуктово описание за проверка на качеството. ', 3),
            'meta_title' => 'Попълнено SEO заглавие',
            'meta_description' => 'Попълнено SEO описание за продукта.',
            'name_translations' => ['en' => 'Image quality product'],
            'description_translations' => ['en' => 'Detailed English product description.'],
            'meta_title_translations' => ['en' => 'Image quality product'],
            'specifications' => ['CPU' => 'Intel Core i7'],
        ], $overrides));
    }

    private function addImage(
        Product $product,
        ?string $altText,
        bool $primary = false,
        int $order = 1,
        ?string $path = null,
    ): ProductImage {
        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path ?? 'products/image-quality-'.$product->id.'-'.$order.'.jpg',
            'alt_text' => $altText,
            'sort_order' => $order,
            'is_primary' => $primary,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function protectedTableCounts(): array
    {
        return collect([
            'products',
            'product_images',
            'supplier_products',
            'product_supplier_offers',
            'categories',
            'brands',
            'product_attribute_values',
            'category_product_attributes',
            'product_quality_flags',
            'product_quality_flag_assignments',
            'suppliers',
            'users',
            'roles',
            'permissions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
