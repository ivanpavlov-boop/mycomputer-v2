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
use App\Services\Products\ProductDataQualitySummaryService;
use App\Services\Products\ProductImageQualityResult;
use App\Services\Products\ProductSeoDescriptionQualityResult;
use App\Services\Products\ProductSeoDescriptionQualityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductSeoDescriptionQualityWorkflowTest extends TestCase
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

    public function test_complete_content_has_success_state_and_expected_scores(): void
    {
        $result = app(ProductSeoDescriptionQualityService::class)->evaluate($this->qualityProduct());

        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_COMPLETE, $result->state);
        $this->assertSame('SEO, описанията и локализацията са попълнени', $result->stateLabel);
        $this->assertSame('success', $result->stateColor);
        $this->assertSame('2/2 · 100%', $result->seoScoreLabel);
        $this->assertSame('2/2 · 100%', $result->descriptionScoreLabel);
        $this->assertSame('3/3 · 100%', $result->englishScoreLabel);
        $this->assertFalse($result->weakDescription);
        $this->assertSame([], $result->issues);
        $this->assertSame('Неизвестно', ProductSeoDescriptionQualityResult::labelFor('unexpected'));
        $this->assertSame('gray', ProductSeoDescriptionQualityResult::colorFor('unexpected'));
    }

    public function test_description_states_follow_required_priority_without_normalizing_stored_html(): void
    {
        $bothMissing = $this->qualityProduct([
            'short_description' => '<p><br></p>',
            'description' => '<div>&nbsp;</div>',
        ]);
        $fullMissing = $this->qualityProduct(['description' => '   ']);
        $shortMissing = $this->qualityProduct(['short_description' => '<p></p>']);
        $service = app(ProductSeoDescriptionQualityService::class);

        $both = $service->evaluate($bothMissing);
        $full = $service->evaluate($fullMissing);
        $short = $service->evaluate($shortMissing);

        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS, $both->state);
        $this->assertSame('0/2 · 0%', $both->descriptionScoreLabel);
        $this->assertSame(2, $both->warningIssueCount);
        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_MISSING_FULL_DESCRIPTION, $full->state);
        $this->assertSame('1/2 · 50%', $full->descriptionScoreLabel);
        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_MISSING_SHORT_DESCRIPTION, $short->state);
        $this->assertSame('1/2 · 50%', $short->descriptionScoreLabel);
        $this->assertSame('<p><br></p>', $bothMissing->fresh()->short_description);
        $this->assertSame('<div>&nbsp;</div>', $bothMissing->fresh()->description);
        $this->assertSame('   ', $fullMissing->fresh()->description);
    }

    public function test_utf8_empty_rich_text_is_portable_across_query_filters_and_statistics(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $literalNbsp = $this->qualityProduct([
            'ean' => null,
            'short_description' => "\u{00A0}",
            'description' => "\u{00A0}",
        ]);
        $htmlEntities = $this->qualityProduct([
            'ean' => null,
            'short_description' => '&nbsp;',
            'description' => '<p>&#160;</p>',
        ]);
        $emptyWrappers = $this->qualityProduct([
            'ean' => null,
            'short_description' => '<p></p>',
            'description' => '<div><br></div>',
        ]);
        $zeroWidth = $this->qualityProduct([
            'ean' => null,
            'short_description' => "\u{200B}",
            'description' => "<p>\u{200B}</p>",
        ]);
        $meaningful = $this->qualityProduct([
            'ean' => null,
            'short_description' => '<p>Смислено кратко описание с достатъчно съдържание</p>',
            'description' => '<div>'.str_repeat('Смислено подробно българско описание. ', 3).'</div>',
        ]);
        $products = collect([$literalNbsp, $htmlEntities, $emptyWrappers, $zeroWidth, $meaningful]);
        $before = $products->mapWithKeys(fn (Product $product): array => [
            $product->id => $product->fresh()->getAttributes(),
        ])->all();
        $service = app(ProductSeoDescriptionQualityService::class);
        $emptyIds = [$literalNbsp->id, $htmlEntities->id, $emptyWrappers->id, $zeroWidth->id];

        foreach ([$literalNbsp, $htmlEntities, $emptyWrappers, $zeroWidth] as $product) {
            $this->assertSame(
                ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS,
                $service->evaluate($product)->state,
            );
        }

        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_COMPLETE, $service->evaluate($meaningful)->state);
        $this->assertSame(
            $emptyIds,
            $service->applyStateQuery(
                Product::query()->whereKey($products->pluck('id')),
                ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS,
            )->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            4,
            $service->countWithMissingDescriptionsFor(Product::query()->whereKey($products->pluck('id'))),
        );

        $sql = $service->applyStateQuery(
            Product::query(),
            ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS,
        )->toSql();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertStringContainsString('CONVERT(0xC2A0 USING utf8mb4)', $sql);
            $this->assertStringContainsString('CONVERT(0xE2808B USING utf8mb4)', $sql);
            $this->assertStringNotContainsString('CHAR(160)', $sql);
            $this->assertStringNotContainsString('CHAR(8203)', $sql);
        } else {
            $this->assertStringContainsString('CHAR(160)', $sql);
            $this->assertStringContainsString('CHAR(8203)', $sql);
        }

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('seo_description_quality_state', ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS)
            ->assertCanSeeTableRecords([$literalNbsp, $htmlEntities, $emptyWrappers, $zeroWidth])
            ->assertCanNotSeeTableRecords([$meaningful]);
        Livewire::test(ProductDataQualityQueueStats::class)
            ->assertSee('Липсват описания');

        $this->assertSame("\u{00A0}", $literalNbsp->fresh()->short_description);
        $this->assertSame("\u{00A0}", $literalNbsp->fresh()->description);
        $this->assertSame('&nbsp;', $htmlEntities->fresh()->short_description);
        $this->assertSame('<p>&#160;</p>', $htmlEntities->fresh()->description);
        $this->assertSame('<p></p>', $emptyWrappers->fresh()->short_description);
        $this->assertSame('<div><br></div>', $emptyWrappers->fresh()->description);
        $this->assertSame("\u{200B}", $zeroWidth->fresh()->short_description);
        $this->assertSame("<p>\u{200B}</p>", $zeroWidth->fresh()->description);
        $this->assertSame(
            $before,
            $products->mapWithKeys(fn (Product $product): array => [
                $product->id => $product->fresh()->getAttributes(),
            ])->all(),
        );
    }

    public function test_seo_states_distinguish_both_missing_from_one_missing(): void
    {
        $bothMissing = $this->qualityProduct(['meta_title' => ' ', 'meta_description' => null]);
        $oneMissing = $this->qualityProduct(['meta_description' => '   ']);
        $service = app(ProductSeoDescriptionQualityService::class);

        $both = $service->evaluate($bothMissing);
        $one = $service->evaluate($oneMissing);

        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_MISSING_SEO, $both->state);
        $this->assertSame('danger', $both->stateColor);
        $this->assertSame('0/2 · 0%', $both->seoScoreLabel);
        $this->assertSame(2, $both->criticalIssueCount);
        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_INCOMPLETE_SEO, $one->state);
        $this->assertSame('warning', $one->stateColor);
        $this->assertSame('1/2 · 50%', $one->seoScoreLabel);
        $this->assertSame('   ', $oneMissing->fresh()->meta_description);
    }

    public function test_existing_weak_description_rule_is_reused_without_a_second_threshold(): void
    {
        $product = $this->qualityProduct([
            'short_description' => 'Кратко',
            'description' => str_repeat('Достатъчно подробно продуктово описание. ', 3),
        ]);
        $scanner = app(ProductDataQualityScanner::class);
        $result = app(ProductSeoDescriptionQualityService::class)->evaluate($product);

        $this->assertTrue($scanner->productHasIssue($product, ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION));
        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_WEAK_DESCRIPTION, $result->state);
        $this->assertTrue($result->weakDescription);
        $this->assertSame(1, collect($result->issues)->where('code', 'weak_description')->count());
        $this->assertContains('Допълнете краткото и пълното описание', $result->nextSteps);
        $this->assertSame(ProductDataQualityScanner::MIN_SHORT_DESCRIPTION_LENGTH, 30);
        $this->assertSame(ProductDataQualityScanner::MIN_DESCRIPTION_LENGTH, 80);
    }

    public function test_missing_english_uses_existing_three_field_contract_and_remains_non_blocking(): void
    {
        $product = $this->qualityProduct([
            'name_translations' => ['en' => '   '],
            'short_description_translations' => null,
            'description_translations' => ['en' => '<p><br></p>'],
            'meta_title_translations' => ['en' => 'English SEO title'],
            'meta_description_translations' => null,
            'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
        ]);
        $result = app(ProductSeoDescriptionQualityService::class)->evaluate($product);

        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_MISSING_EN_TRANSLATION, $result->state);
        $this->assertSame('info', $result->stateColor);
        $this->assertSame('1/3 · 33%', $result->englishScoreLabel);
        $this->assertSame([
            'Липсва английско име',
            'Липсва английско пълно описание',
        ], $result->missingEnglishFieldLabels);
        $this->assertSame(2, $result->recommendationIssueCount);
        $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $product->fresh()->workflow_status);
        $this->assertContains('Добавете английска локализация', $result->nextSteps);
        $this->assertNotContains('Липсва английско кратко описание', $result->missingEnglishFieldLabels);
        $this->assertNotContains('Липсва английско SEO описание', $result->missingEnglishFieldLabels);
    }

    public function test_meaningful_rich_text_remains_present_and_service_is_request_memoized(): void
    {
        $product = $this->qualityProduct([
            'short_description' => '<p>Текст с &lt;, &gt;, &amp; и кавички</p>',
            'description' => '<div>'.str_repeat('Смислено съдържание &amp; технически данни. ', 3).'</div>',
        ])->fresh();
        $service = app(ProductSeoDescriptionQualityService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $service->evaluate($product);
        $queriesAfterFirst = count(DB::getQueryLog());
        $second = $service->evaluate($product);
        $queriesAfterSecond = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertTrue($first->shortDescriptionPresent);
        $this->assertTrue($first->fullDescriptionPresent);
        $this->assertGreaterThan(0, $first->shortDescriptionLength);
        $this->assertSame($first, $second);
        $this->assertSame($queriesAfterFirst, $queriesAfterSecond);
        $this->assertSame(0, $queriesAfterFirst);
    }

    public function test_queue_presents_compact_scores_state_and_no_content_mutation_actions(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $product = $this->qualityProduct([
            'ean' => null,
            'meta_description' => null,
            'name_translations' => null,
        ]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableColumnStateSet('seo_completeness', '1/2 · 50%', $product)
            ->assertTableColumnStateSet('description_completeness', '2/2 · 100%', $product)
            ->assertTableColumnStateSet('english_localization_completeness', '2/3 · 67%', $product)
            ->assertTableColumnStateSet('seo_description_quality', 'SEO данните са непълни', $product)
            ->assertSee('Категория / марка')
            ->assertSee('Качество на снимките')
            ->assertTableActionDoesNotExist('editSeo', null, $product)
            ->assertTableActionDoesNotExist('generateDescription', null, $product)
            ->assertTableActionDoesNotExist('translateContent', null, $product)
            ->assertTableBulkActionDoesNotExist('rewriteContent');
    }

    public function test_combined_state_filter_is_exact_prioritized_and_composable(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $missingDescriptions = $this->qualityProduct([
            'ean' => null,
            'short_description' => '<p><br></p>',
            'description' => '<div>&nbsp;</div>',
            'meta_title' => null,
            'meta_description' => null,
        ]);
        $missingFull = $this->qualityProduct(['ean' => null, 'description' => ' ']);
        $missingShort = $this->qualityProduct(['ean' => null, 'short_description' => '<p></p>']);
        $missingSeo = $this->qualityProduct(['ean' => null, 'meta_title' => ' ', 'meta_description' => null]);
        $incompleteSeo = $this->qualityProduct(['ean' => null, 'meta_description' => null]);
        $weak = $this->qualityProduct(['ean' => null, 'short_description' => 'Кратко']);
        $missingEnglish = $this->qualityProduct(['ean' => null, 'name_translations' => ['en' => ' ']]);
        $complete = $this->qualityProduct(['ean' => null]);
        $records = [$missingDescriptions, $missingFull, $missingShort, $missingSeo, $incompleteSeo, $weak, $missingEnglish, $complete];

        Livewire::test(ListProductDataQualityQueue::class)->assertCanSeeTableRecords($records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_MISSING_DESCRIPTIONS, $missingDescriptions, $records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_MISSING_FULL_DESCRIPTION, $missingFull, $records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_MISSING_SHORT_DESCRIPTION, $missingShort, $records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_MISSING_SEO, $missingSeo, $records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_INCOMPLETE_SEO, $incompleteSeo, $records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_WEAK_DESCRIPTION, $weak, $records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_MISSING_EN_TRANSLATION, $missingEnglish, $records);
        $this->assertSeoFilterShows(ProductSeoDescriptionQualityResult::STATE_COMPLETE, $complete, $records);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('category_brand_state', ProductCategoryBrandQualityResult::STATE_COMPLETE)
            ->filterTable('image_quality_state', ProductImageQualityResult::STATE_COMPLETE)
            ->filterTable('seo_description_quality_state', ProductSeoDescriptionQualityResult::STATE_INCOMPLETE_SEO)
            ->assertCanSeeTableRecords([$incompleteSeo])
            ->assertCanNotSeeTableRecords(array_values(array_filter(
                $records,
                fn (Product $product): bool => ! $product->is($incompleteSeo),
            )));

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_SEO)
            ->assertCanSeeTableRecords([$missingDescriptions, $missingSeo, $incompleteSeo])
            ->assertCanNotSeeTableRecords([$missingFull, $missingShort, $weak, $missingEnglish, $complete]);
    }

    public function test_statistics_use_the_existing_queue_scope_and_bounded_state_queries(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $missingSeo = $this->qualityProduct(['ean' => null, 'meta_title' => null, 'meta_description' => null]);
        $missingDescriptions = $this->qualityProduct(['ean' => null, 'short_description' => null, 'description' => null]);
        $weak = $this->qualityProduct(['ean' => null, 'short_description' => 'Кратко']);
        $missingEnglish = $this->qualityProduct(['ean' => null, 'name_translations' => null]);
        $complete = $this->qualityProduct(['ean' => null]);
        $outsideQueue = $this->qualityProduct();
        $scanner = app(ProductDataQualityScanner::class);
        $service = app(ProductSeoDescriptionQualityService::class);
        $scope = fn () => $scanner->applyQueueScope(Product::query());
        $counts = $service->countsFor($scope());

        $this->assertSame(1, $service->countWithMissingSeoFor($scope()));
        $this->assertSame(1, $service->countWithMissingDescriptionsFor($scope()));
        $this->assertSame(2, $service->countWithWeakDescriptionFor($scope()));
        $this->assertSame(1, $service->countWithMissingEnglishFor($scope()));
        $this->assertSame(1, $counts[ProductSeoDescriptionQualityResult::STATE_COMPLETE]);
        $this->assertSame(5, array_sum($counts));
        $this->assertNotContains($outsideQueue->id, $scope()->pluck('id')->all());
        $this->assertContains($complete->id, $scope()->pluck('id')->all());
        $this->assertContains($missingSeo->id, $scope()->pluck('id')->all());
        $this->assertContains($missingDescriptions->id, $scope()->pluck('id')->all());
        $this->assertContains($weak->id, $scope()->pluck('id')->all());
        $this->assertContains($missingEnglish->id, $scope()->pluck('id')->all());

        Livewire::test(ProductDataQualityQueueStats::class)
            ->assertSee('Липсва SEO')
            ->assertSee('Липсват описания')
            ->assertSee('Слабо описание')
            ->assertSee('Липсва EN локализация')
            ->assertSee('Съдържанието е попълнено');
    }

    public function test_unified_summary_reuses_scanner_counts_and_escapes_model_content(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $unsafe = '<script>alert("content")</script> & <img onerror=alert(1)>';
        $missingSeo = $this->qualityProduct([
            'name' => $unsafe,
            'meta_title' => null,
            'meta_description' => null,
        ]);
        $summary = app(ProductDataQualitySummaryService::class)->summarize($missingSeo);
        $html = view('filament.products.data-quality-summary', compact('summary'))->render();

        $this->assertSame(ProductSeoDescriptionQualityResult::STATE_MISSING_SEO, $summary->seoDescriptionQuality->state);
        $this->assertSame(1, collect($summary->coreIssues)->where('code', ProductDataQualityScanner::ISSUE_MISSING_SEO)->count());
        $this->assertSame(1, $summary->criticalIssueCount);
        $this->assertSame(1, collect($summary->nextSteps)->where(fn (string $step): bool => $step === 'Попълнете SEO заглавие и описание')->count());
        $this->assertStringContainsString('SEO, описания и английска локализация', $html);
        $this->assertStringContainsString('0/2 · 0%', $html);
        $this->assertStringNotContainsString($unsafe, $html);
        $this->assertStringNotContainsString('<script>alert("content")</script>', $html);

        $weak = $this->qualityProduct(['short_description' => 'Кратко']);
        $weakSummary = app(ProductDataQualitySummaryService::class)->summarize($weak);
        $this->assertSame(1, collect($weakSummary->coreIssues)->where('code', ProductDataQualityScanner::ISSUE_WEAK_DESCRIPTION)->count());
        $this->assertSame(1, collect($weakSummary->nextSteps)->where(fn (string $step): bool => $step === 'Допълнете краткото и пълното описание')->count());

        $missingEnglish = $this->qualityProduct(['name_translations' => null]);
        $englishSummary = app(ProductDataQualitySummaryService::class)->summarize($missingEnglish);
        $this->assertSame(1, collect($englishSummary->coreIssues)->where('code', ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION)->count());
        $this->assertSame(1, $englishSummary->recommendationIssueCount);
    }

    public function test_render_filter_stats_and_summary_are_query_bounded_and_zero_mutation(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        Http::preventStrayRequests();
        $products = collect(range(1, 12))->map(fn (int $index): Product => $this->qualityProduct([
            'ean' => null,
            'meta_description' => $index % 2 === 0 ? null : 'Попълнено SEO описание.',
        ]));
        $product = $products->first();
        SupplierProduct::query()->create([
            'supplier_id' => $product->supplier_id,
            'supplier_sku' => 'SEO-CONTENT-STAGED',
            'name' => 'Untouched staging row',
            'currency' => 'EUR',
            'raw_data' => ['quality' => 'untouched'],
            'payload_hash' => 'seo-content-staged-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);
        $beforeCounts = $this->protectedTableCounts();
        $beforeProducts = Product::query()->orderBy('id')->get()->map->getAttributes()->all();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListProductDataQualityQueue::class)->set('tableRecordsPerPage', 25);
        $queueQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        app(ProductSeoDescriptionQualityService::class)->evaluate($product->fresh());
        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('seo_description_quality_state', ProductSeoDescriptionQualityResult::STATE_INCOMPLETE_SEO);
        Livewire::test(ProductDataQualityQueueStats::class);
        app(ProductDataQualitySummaryService::class)->summarize($product->fresh());
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk();

        $this->assertLessThanOrEqual(25, $queueQueryCount, "Queue used {$queueQueryCount} queries for 12 Products.");
        $this->assertSame($beforeCounts, $this->protectedTableCounts());
        $this->assertSame($beforeProducts, Product::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_manual_form_workflow_and_authorization_remain_unchanged(): void
    {
        $product = $this->qualityProduct(['ean' => null]);
        $viewer = $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableActionHidden('editProduct', $product)
            ->assertTableActionHidden('openProduct', $product)
            ->assertTableBulkActionDoesNotExist('rewriteContent');
        $this->assertTrue($viewer->isActiveAdminAccount());
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();

        $superAdmin = $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $before = $product->fresh()->getAttributes();
        $this->assertTrue($superAdmin->isActiveAdminAccount());
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('SEO, описания и английска локализация');
        $this->get(ProductResource::getUrl('create'))
            ->assertOk()
            ->assertDontSee('SEO, описания и английска локализация');
        $this->assertSame($before, $product->fresh()->getAttributes());
    }

    /**
     * @param  array<int, Product>  $records
     */
    private function assertSeoFilterShows(string $state, Product $visible, array $records): void
    {
        $recordIds = collect($records)->pluck('id');
        $matchingIds = app(ProductSeoDescriptionQualityService::class)
            ->applyStateQuery(Product::query()->whereKey($recordIds), $state)
            ->pluck('id')
            ->all();

        $this->assertSame([$visible->id], $matchingIds, "Unexpected direct SQL result for state [{$state}].");

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('seo_description_quality_state', $state)
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
        $product = Product::factory()->create(array_merge([
            'name' => 'Продукт за SEO проверка',
            'sku' => fake()->unique()->bothify('SEO-####??'),
            'ean' => fake()->unique()->ean13(),
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'short_description' => str_repeat('Подробно кратко описание за проверка. ', 2),
            'description' => str_repeat('Подробно продуктово описание за проверка на качеството и съдържанието. ', 3),
            'meta_title' => 'Попълнено SEO заглавие',
            'meta_description' => 'Попълнено SEO описание за продукта.',
            'name_translations' => ['en' => 'SEO quality product'],
            'description_translations' => ['en' => 'Detailed English product description.'],
            'meta_title_translations' => ['en' => 'SEO quality product'],
            'specifications' => ['CPU' => 'Intel Core i7'],
        ], $overrides));

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/seo-quality-'.$product->id.'.jpg',
            'alt_text' => 'Продуктова снимка',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

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
            'product_supplier_offers',
            'product_images',
            'product_attribute_values',
            'category_product_attributes',
            'product_quality_flags',
            'product_quality_flag_assignments',
            'categories',
            'brands',
            'suppliers',
            'users',
            'roles',
            'permissions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
