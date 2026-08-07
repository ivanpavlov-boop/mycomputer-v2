<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductDataQualityQueue\Pages\ListProductDataQualityQueue;
use App\Filament\Resources\ProductDataQualityQueue\ProductDataQualityQueueResource;
use App\Filament\Resources\ProductDataQualityQueue\Widgets\ProductDataQualityQueueStats;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Models\User;
use App\Services\Products\ProductDataQualityScanner;
use App\Services\Products\ProductSpecificationQualityResult;
use App\Services\Products\ProductSpecificationQualityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDataQualityQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorized_admin_can_view_product_data_quality_queue(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $product = $this->qualityReadyProduct([
            'meta_title' => null,
            'meta_description' => '',
        ]);

        $this->assertTrue(ProductDataQualityQueueResource::canViewAny());
        $this->assertTrue(ProductDataQualityQueueResource::shouldRegisterNavigation());
        $this->get(ProductDataQualityQueueResource::getUrl())->assertOk()
            ->assertSee('Опашка за качество на продуктови данни');

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertSee('Липсва SEO');
    }

    public function test_queue_access_is_read_only_and_limited_to_catalog_content_roles(): void
    {
        $product = Product::factory()->create();

        foreach ([
            User::ROLE_SUPER_ADMIN,
            User::ROLE_CATALOG_MANAGER,
            User::ROLE_PRODUCT_EDITOR,
            User::ROLE_PRODUCT_DATA_ENTRY,
            User::ROLE_SEO_MARKETING,
            User::ROLE_VIEWER_AUDITOR,
        ] as $role) {
            $this->actingAsRole($role);

            $this->assertTrue(ProductDataQualityQueueResource::canViewAny(), "{$role} should view the queue.");
            $this->assertFalse(ProductDataQualityQueueResource::canCreate());
            $this->assertFalse(ProductDataQualityQueueResource::canEdit($product));
            $this->assertFalse(ProductDataQualityQueueResource::canDelete($product));
            $this->assertFalse(ProductDataQualityQueueResource::canDeleteAny());
        }

        $this->actingAsRole(User::ROLE_ORDER_MANAGER);

        $this->assertFalse(ProductDataQualityQueueResource::canViewAny());
        $this->get(ProductDataQualityQueueResource::getUrl())->assertForbidden();
    }

    public function test_issue_type_filter_shows_products_matching_computed_issue(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);

        $missingSeo = $this->qualityReadyProduct([
            'name' => 'Missing SEO Product',
            'sku' => 'DQ-MISSING-SEO',
            'meta_title' => null,
            'meta_description' => '',
        ]);
        $missingImage = $this->qualityReadyProduct([
            'name' => 'Missing Image Product',
            'sku' => 'DQ-MISSING-IMAGE',
        ], withImage: false);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_SEO)
            ->assertCanSeeTableRecords([$missingSeo])
            ->assertCanNotSeeTableRecords([$missingImage])
            ->assertSee('Липсва SEO');
    }

    public function test_quality_flag_filter_shows_products_with_active_flag_assignments(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);

        $flaggedProduct = $this->qualityReadyProduct(['name' => 'Flagged data product']);
        $unflaggedProduct = $this->qualityReadyProduct([
            'name' => 'Unflagged missing image product',
        ], withImage: false);
        $flag = ProductQualityFlag::query()->create([
            'code' => 'needs_specs_review',
            'label_bg' => 'Needs specs review',
            'label_en' => 'Needs specs review',
            'severity' => ProductQualityFlag::SEVERITY_HIGH,
            'responsible_role' => User::ROLE_PRODUCT_EDITOR,
            'type' => ProductQualityFlag::TYPE_DATA,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        ProductQualityFlagAssignment::query()->create([
            'product_id' => $flaggedProduct->id,
            'product_quality_flag_id' => $flag->id,
            'status' => ProductQualityFlagAssignment::STATUS_ACTIVE,
            'note' => 'Review specs before publishing.',
        ]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('quality_flag', $flag->id)
            ->assertCanSeeTableRecords([$flaggedProduct])
            ->assertCanNotSeeTableRecords([$unflaggedProduct])
            ->assertSee('Needs specs review');
    }

    public function test_queue_table_handles_missing_images_and_multiple_quality_flags(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);

        $product = $this->qualityReadyProduct([
            'name' => 'Multi flag missing image product',
            'sku' => 'DQ-MULTI-FLAG',
            'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
            'active' => false,
            'product_status' => 'draft',
            'published_at' => null,
        ], withImage: false);

        foreach (['Needs image review', 'Needs data cleanup'] as $index => $label) {
            $flag = ProductQualityFlag::query()->create([
                'code' => 'queue_multi_flag_'.$index,
                'label_bg' => $label,
                'label_en' => $label,
                'severity' => ProductQualityFlag::SEVERITY_MEDIUM,
                'responsible_role' => User::ROLE_PRODUCT_EDITOR,
                'type' => ProductQualityFlag::TYPE_DATA,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);

            ProductQualityFlagAssignment::query()->create([
                'product_id' => $product->id,
                'product_quality_flag_id' => $flag->id,
                'status' => ProductQualityFlagAssignment::STATUS_ACTIVE,
            ]);
        }

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertTableActionExists('reviewFlags', null, $product)
            ->assertTableActionHasUrl('reviewFlags', ProductResource::getUrl('edit', ['record' => $product]), $product)
            ->assertSee('Липсва снимка')
            ->assertSee('Needs image review')
            ->assertSee('Needs data cleanup')
            ->assertSee('Брой флагове')
            ->assertSee('Скрит')
            ->assertSee((string) $product->id)
            ->assertSee($product->sku);
    }

    public function test_queue_filters_by_workflow_visibility_missing_image_and_no_flags(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);

        $hiddenNoFlag = $this->qualityReadyProduct([
            'name' => 'Hidden missing image without flags',
            'sku' => 'DQ-HIDDEN-NO-FLAGS',
            'workflow_status' => Product::WORKFLOW_DRAFT,
            'active' => false,
            'product_status' => 'draft',
            'published_at' => null,
        ], withImage: false);
        $publishedFlagged = $this->qualityReadyProduct([
            'name' => 'Public flagged product',
            'sku' => 'DQ-PUBLIC-FLAGGED',
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'active' => true,
            'product_status' => 'active',
            'published_at' => now(),
        ]);
        $flag = ProductQualityFlag::query()->create([
            'code' => 'public_flagged_product',
            'label_bg' => 'Public flag',
            'label_en' => 'Public flag',
            'severity' => ProductQualityFlag::SEVERITY_LOW,
            'responsible_role' => User::ROLE_PRODUCT_EDITOR,
            'type' => ProductQualityFlag::TYPE_CONTENT,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductQualityFlagAssignment::query()->create([
            'product_id' => $publishedFlagged->id,
            'product_quality_flag_id' => $flag->id,
            'status' => ProductQualityFlagAssignment::STATUS_ACTIVE,
        ]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('workflow_status', Product::WORKFLOW_DRAFT)
            ->assertCanSeeTableRecords([$hiddenNoFlag])
            ->assertCanNotSeeTableRecords([$publishedFlagged]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('visibility', 'public')
            ->assertCanSeeTableRecords([$publishedFlagged])
            ->assertCanNotSeeTableRecords([$hiddenNoFlag]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('missing_image', true)
            ->assertCanSeeTableRecords([$hiddenNoFlag])
            ->assertCanNotSeeTableRecords([$publishedFlagged]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('has_quality_flags', false)
            ->assertCanSeeTableRecords([$hiddenNoFlag])
            ->assertCanNotSeeTableRecords([$publishedFlagged]);
    }

    public function test_queue_searches_by_product_name_sku_and_identifier(): void
    {
        $this->actingAsRole(User::ROLE_PRODUCT_EDITOR);

        $target = $this->qualityReadyProduct([
            'name' => 'Searchable Queue Laptop',
            'sku' => 'DQ-SEARCH-123',
        ], withImage: false);
        $other = $this->qualityReadyProduct([
            'name' => 'Different Queue Product',
            'sku' => 'DQ-OTHER-456',
        ], withImage: false);

        Livewire::test(ListProductDataQualityQueue::class)
            ->searchTable('Searchable Queue Laptop')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->searchTable('DQ-SEARCH-123')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->searchTable((string) $target->id)
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_queue_detects_missing_english_translation_without_using_bg_fallback(): void
    {
        $this->actingAsRole(User::ROLE_SEO_MARKETING);

        $product = $this->qualityReadyProduct([
            'name' => 'Bulgarian fallback only',
            'name_translations' => ['bg' => 'Bulgarian fallback only'],
            'description_translations' => ['bg' => 'Bulgarian description only'],
            'meta_title_translations' => ['bg' => 'Bulgarian meta only'],
        ]);

        $scanner = app(ProductDataQualityScanner::class);

        $this->assertTrue($scanner->productHasIssue($product->fresh(), ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION));

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION)
            ->assertCanSeeTableRecords([$product])
            ->assertSee('Липсва EN превод');
    }

    public function test_queue_admin_labels_are_bulgarian_without_mutating_products(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);

        $product = $this->qualityReadyProduct([
            'name' => 'Localized queue labels product',
            'sku' => 'DQ-BG-LABELS',
            'meta_title' => null,
            'meta_description' => '',
        ], withImage: false);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertSee('Снимка')
            ->assertSee('Статус на снимка')
            ->assertSee('Продукт')
            ->assertSee('Източник')
            ->assertSee('Работен статус')
            ->assertSee('Видимост')
            ->assertSee('Статус на продукта')
            ->assertSee('Открити проблеми')
            ->assertSee('Флагове за качество')
            ->assertSee('Редакция на продукт')
            ->assertSee('Отвори в нов таб');

        $product->refresh();

        $this->assertSame('Localized queue labels product', $product->name);
        $this->assertSame('DQ-BG-LABELS', $product->sku);
    }

    public function test_queue_links_to_existing_product_edit_page_without_additional_mutation_actions(): void
    {
        $this->actingAsRole(User::ROLE_PRODUCT_EDITOR);

        $product = $this->qualityReadyProduct([
            'name' => 'Editable queue product',
            'meta_description' => '',
        ]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertTableActionExists('editProduct', null, $product)
            ->assertTableActionHasUrl('editProduct', ProductResource::getUrl('edit', ['record' => $product]), $product)
            ->assertTableActionExists('openProduct', null, $product)
            ->assertTableActionHasUrl('openProduct', ProductResource::getUrl('edit', ['record' => $product]), $product)
            ->assertTableActionShouldOpenUrlInNewTab('openProduct', $product)
            ->assertTableActionDoesNotExist('delete', null, $product);
    }

    public function test_queue_does_not_modify_products_or_catalog_sync_safety_flags(): void
    {
        $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);

        $product = $this->qualityReadyProduct([
            'name' => 'Read only queue product',
            'price' => 149,
            'quantity' => 3,
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'active' => true,
        ], withImage: false);

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product]);

        $product->refresh();

        $this->assertSame('149.00', $product->price);
        $this->assertSame(3, $product->quantity);
        $this->assertSame(Product::WORKFLOW_PUBLISHED, $product->workflow_status);
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    public function test_json_specification_completeness_matches_for_models_queries_filters_counts_and_queue_scope(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $category = Category::factory()->create(['name' => 'JSON specifications']);
        $attribute = $this->qualityAttribute('JSON configuration', ProductAttribute::TYPE_JSON);
        $this->qualityAssignment($category, $attribute, required: true);

        $cases = [
            'database_null' => [null, false, 'null'],
            'json_null' => ['null', false, 'null'],
            'empty_string' => ['""', false, 'string'],
            'string_scalar' => ['"ready"', false, 'string'],
            'number_scalar' => ['42', false, 'int'],
            'boolean_false' => ['false', false, 'bool'],
            'boolean_true' => ['true', false, 'bool'],
            'empty_array' => ['[]', false, 'array'],
            'empty_object' => ['{}', false, 'stdClass'],
            'non_empty_array' => ['["ready"]', true, 'array'],
            'non_empty_object' => ['{"state":"ready"}', true, 'stdClass'],
        ];

        if (DB::connection()->getDriverName() === 'sqlite') {
            $cases['malformed_json'] = ['not-json', false, 'malformed'];
        }

        $products = collect();
        $valueIds = collect();

        foreach ($cases as $key => [$storedJson, $filled, $rootType]) {
            $product = $this->specificationQualityReadyProduct($category, "JSON {$key}");
            $value = ProductAttributeValue::factory()->create([
                'product_id' => $product->id,
                'product_attribute_id' => $attribute->id,
                'custom_value' => null,
                'value_text' => null,
                'value_json' => null,
            ]);

            if ($storedJson !== null) {
                DB::table('product_attribute_values')
                    ->where('id', $value->id)
                    ->update(['value_json' => $storedJson]);
            }

            $rawValue = DB::table('product_attribute_values')->where('id', $value->id)->value('value_json');

            if ($rootType === 'malformed') {
                json_decode((string) $rawValue);
                $this->assertNotSame(JSON_ERROR_NONE, json_last_error(), "Malformed JSON was normalized for {$key}.");
            } else {
                $decoded = $rawValue === null
                    ? null
                    : json_decode((string) $rawValue, flags: JSON_THROW_ON_ERROR);

                $this->assertSame($rootType, get_debug_type($decoded), "Stored JSON root changed for {$key}.");
            }

            $products->put($key, $product->fresh());
            $valueIds->push($value->id);
        }

        $productIds = $products->pluck('id');
        $before = [
            'products' => DB::table('products')->whereIn('id', $productIds)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'values' => DB::table('product_attribute_values')->whereIn('id', $valueIds)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
        $scanner = app(ProductDataQualityScanner::class);
        $quality = app(ProductSpecificationQualityService::class);
        $incomplete = collect();
        $complete = collect();

        foreach ($cases as $key => [, $filled]) {
            /** @var Product $product */
            $product = $products[$key];
            $result = $quality->evaluate($product);
            $expectedStatus = $filled
                ? ProductSpecificationQualityResult::STATUS_GOOD
                : ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED;

            $this->assertSame($expectedStatus, $result->status, "Model status diverged for {$key}.");
            $this->assertSame(
                ! $filled,
                $scanner->productHasIssue($product, ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES),
                "Model issue evaluation diverged for {$key}.",
            );
            $this->assertSame(
                ! $filled,
                $scanner->applyIssueQuery(
                    Product::query()->whereKey($product->id),
                    ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES,
                )->exists(),
                "Database issue query diverged for {$key}.",
            );
            $this->assertSame(
                ! $filled,
                $quality->applyStateQuery(
                    Product::query()->whereKey($product->id),
                    ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED,
                )->exists(),
                "Missing-required query diverged for {$key}.",
            );
            $this->assertSame(
                $filled,
                $quality->applyStateQuery(
                    Product::query()->whereKey($product->id),
                    ProductSpecificationQualityResult::STATUS_GOOD,
                )->exists(),
                "Good-state query diverged for {$key}.",
            );

            ($filled ? $complete : $incomplete)->push($product);
        }

        $expectedIncompleteIds = $incomplete->pluck('id')->sort()->values()->all();
        $expectedCompleteIds = $complete->pluck('id')->sort()->values()->all();
        $issueIds = $scanner->applyIssueQuery(
            Product::query()->whereKey($productIds),
            ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES,
        )->orderBy('id')->pluck('id')->all();
        $queueIds = $scanner->applyQueueScope(Product::query()->whereKey($productIds))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $counts = $quality->countsFor(Product::query()->whereKey($productIds));

        $this->assertSame($expectedIncompleteIds, $issueIds);
        $this->assertSame($expectedIncompleteIds, $queueIds);
        $this->assertSame($incomplete->count(), $counts[ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED]);
        $this->assertSame(0, $counts[ProductSpecificationQualityResult::STATUS_NEEDS_DATA]);
        $this->assertSame(0, $counts[ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE]);
        $this->assertSame($complete->count(), $counts[ProductSpecificationQualityResult::STATUS_GOOD]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES)
            ->filterTable('specification_quality_state', ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED)
            ->assertCanSeeTableRecords($incomplete)
            ->assertCanNotSeeTableRecords($complete);

        $this->assertSame(
            $expectedCompleteIds,
            $quality->applyStateQuery(
                Product::query()->whereKey($productIds),
                ProductSpecificationQualityResult::STATUS_GOOD,
            )->orderBy('id')->pluck('id')->all(),
        );

        foreach ($products as $product) {
            $quality->evaluate($product);
            $scanner->applyIssueQuery(
                Product::query()->whereKey($product->id),
                ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES,
            )->exists();
        }

        $after = [
            'products' => DB::table('products')->whereIn('id', $productIds)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'values' => DB::table('product_attribute_values')->whereIn('id', $valueIds)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_missing_attributes_uses_exact_authoritative_specification_states_for_models_and_queries(): void
    {
        $scenario = $this->specificationQueueScenario();
        $scanner = app(ProductDataQualityScanner::class);
        $quality = app(ProductSpecificationQualityService::class);
        $expectedStatuses = [
            'missing_required' => ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED,
            'needs_data' => ProductSpecificationQualityResult::STATUS_NEEDS_DATA,
            'inherited_missing' => ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED,
            'invalid_select' => ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED,
            'invalid_multiselect' => ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED,
            'no_template' => ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE,
            'no_category' => ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE,
            'good' => ProductSpecificationQualityResult::STATUS_GOOD,
            'unrelated_issue' => ProductSpecificationQualityResult::STATUS_GOOD,
            'flag_only' => ProductSpecificationQualityResult::STATUS_GOOD,
        ];

        foreach ($expectedStatuses as $key => $status) {
            $product = $scenario[$key];
            $result = $quality->evaluate($product);

            $this->assertSame($status, $result->status, "Unexpected specification state for {$key}.");
            $this->assertSame(
                $result->isIncomplete(),
                $scanner->productHasIssue($product, ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES),
                "Model issue evaluation diverged for {$key}.",
            );
        }

        $incomplete = collect([
            $scenario['missing_required'],
            $scenario['needs_data'],
            $scenario['inherited_missing'],
            $scenario['invalid_select'],
            $scenario['invalid_multiselect'],
            $scenario['no_template'],
            $scenario['no_category'],
        ]);
        $allProducts = collect($scenario)->filter(fn (mixed $value): bool => $value instanceof Product);
        $issueIds = $scanner
            ->applyIssueQuery(
                Product::query()->whereKey($allProducts->pluck('id')),
                ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES,
            )
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($incomplete->pluck('id')->sort()->values()->all(), $issueIds);
        $this->assertTrue($scenario['missing_required']->attributeValues()->exists());
        $this->assertNotEmpty($scenario['missing_required']->specifications);
        $this->assertSame(
            'Непълни характеристики',
            ProductDataQualityScanner::issueOptions()[ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES],
        );

        $queueIds = $scanner->applyQueueScope(Product::query()->whereKey($allProducts->pluck('id')))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $expectedQueueIds = $incomplete
            ->push($scenario['unrelated_issue'])
            ->push($scenario['flag_only'])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedQueueIds, $queueIds);
        $this->assertNotContains($scenario['good']->id, $queueIds);
    }

    public function test_queue_filters_and_statistics_keep_specification_states_exact_and_non_overlapping(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $scenario = $this->specificationQueueScenario();
        $incomplete = collect([
            $scenario['missing_required'],
            $scenario['needs_data'],
            $scenario['inherited_missing'],
            $scenario['invalid_select'],
            $scenario['invalid_multiselect'],
            $scenario['no_template'],
            $scenario['no_category'],
        ]);

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES)
            ->assertCanSeeTableRecords($incomplete)
            ->assertCanNotSeeTableRecords([
                $scenario['good'],
                $scenario['unrelated_issue'],
                $scenario['flag_only'],
            ])
            ->assertSee('Непълни характеристики');

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES)
            ->filterTable('specification_quality_state', ProductSpecificationQualityResult::STATUS_NEEDS_DATA)
            ->assertCanSeeTableRecords([$scenario['needs_data']])
            ->assertCanNotSeeTableRecords($incomplete->reject(
                fn (Product $product): bool => $product->is($scenario['needs_data']),
            ));

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('specification_quality_state', ProductSpecificationQualityResult::STATUS_GOOD)
            ->assertCanSeeTableRecords([$scenario['unrelated_issue'], $scenario['flag_only']])
            ->assertCanNotSeeTableRecords([$scenario['good'], ...$incomplete]);

        $scanner = app(ProductDataQualityScanner::class);
        $quality = app(ProductSpecificationQualityService::class);
        $allProductIds = collect($scenario)
            ->filter(fn (mixed $value): bool => $value instanceof Product)
            ->pluck('id');
        $queueScope = $scanner->applyQueueScope(Product::query()->whereKey($allProductIds));
        $counts = $quality->countsFor($queueScope);

        $this->assertSame(4, $counts[ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED]);
        $this->assertSame(1, $counts[ProductSpecificationQualityResult::STATUS_NEEDS_DATA]);
        $this->assertSame(2, $counts[ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE]);
        $this->assertSame(2, $counts[ProductSpecificationQualityResult::STATUS_GOOD]);
        $this->assertSame((clone $queueScope)->count(), array_sum($counts));

        Livewire::test(ProductDataQualityQueueStats::class)
            ->assertSee('Липсват задължителни характеристики')
            ->assertSee('Непълни препоръчителни характеристики')
            ->assertSee('Няма шаблон за категорията');
    }

    public function test_specification_queue_evaluation_rendering_stats_and_filters_are_read_only_with_bounded_queries(): void
    {
        $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);
        $scenario = $this->specificationQueueScenario();
        $before = $this->protectedQualitySnapshot();
        $scanner = app(ProductDataQualityScanner::class);
        $this->assertTrue($scanner->productHasIssue(
            $scenario['invalid_multiselect'],
            ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES,
        ));
        $scanner->applyIssueQuery(
            Product::query(),
            ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES,
        )->count();

        $queryCount = 0;

        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        Livewire::test(ListProductDataQualityQueue::class)
            ->filterTable('issue_type', ProductDataQualityScanner::ISSUE_MISSING_ATTRIBUTES)
            ->filterTable('specification_quality_state', ProductSpecificationQualityResult::STATUS_MISSING_REQUIRED)
            ->assertCanSeeTableRecords([
                $scenario['missing_required'],
                $scenario['inherited_missing'],
                $scenario['invalid_select'],
                $scenario['invalid_multiselect'],
            ]);

        $queueRenderQueryCount = $queryCount;

        Livewire::test(ProductDataQualityQueueStats::class)
            ->assertSee('Продукти за преглед');

        $this->assertLessThanOrEqual(70, $queueRenderQueryCount);
        $this->assertContains(DB::connection()->getDriverName(), ['sqlite', 'mysql', 'mariadb']);
        $this->assertSame($before, $this->protectedQualitySnapshot());
        $this->assertTrue(ProductDataQualityQueueResource::canViewAny());
        $this->assertFalse(ProductDataQualityQueueResource::canCreate());
        $this->assertFalse(ProductDataQualityQueueResource::canEdit($scenario['missing_required']));
        $this->assertFalse(ProductDataQualityQueueResource::canDelete($scenario['missing_required']));
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
            'name' => 'Quality Ready Product',
            'sku' => fake()->unique()->bothify('DQ-####??'),
            'ean' => fake()->unique()->numerify('#############'),
            'description' => str_repeat('Detailed product description for catalog quality checks. ', 3),
            'short_description' => 'Short description with enough detail.',
            'meta_title' => 'Quality Ready Product',
            'meta_description' => 'SEO description for quality ready product.',
            'name_translations' => ['en' => 'Quality Ready Product'],
            'description_translations' => ['en' => 'Detailed English product description.'],
            'meta_title_translations' => ['en' => 'Quality Ready Product'],
            'specifications' => ['CPU' => 'Intel Core i7'],
        ], $overrides));

        if ($withImage) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'products/test-image.jpg',
                'alt_text' => $product->name,
                'sort_order' => 1,
                'is_primary' => true,
            ]);
        }

        return $product;
    }

    /**
     * @return array<string, Product>
     */
    private function specificationQueueScenario(): array
    {
        $parent = Category::factory()->create(['name' => 'Компютри']);
        $child = Category::factory()->create(['name' => 'Лаптопи', 'parent_id' => $parent->id]);
        $selectCategory = Category::factory()->create(['name' => 'Монитори']);
        $multiselectCategory = Category::factory()->create(['name' => 'Докинг станции']);
        $noTemplateCategory = Category::factory()->create(['name' => 'Без шаблон']);
        $ram = $this->qualityAttribute('RAM', ProductAttribute::TYPE_TEXT);
        $color = $this->qualityAttribute('Цвят', ProductAttribute::TYPE_TEXT);
        $panel = $this->qualityAttribute('Панел', ProductAttribute::TYPE_SELECT);
        $ports = $this->qualityAttribute('Портове', ProductAttribute::TYPE_MULTISELECT);
        $unrelated = $this->qualityAttribute('Друг атрибут', ProductAttribute::TYPE_SELECT);
        AttributeValue::factory()->create(['product_attribute_id' => $panel->id, 'value' => 'IPS']);
        $usb = AttributeValue::factory()->create(['product_attribute_id' => $ports->id, 'value' => 'USB']);
        $wrong = AttributeValue::factory()->create(['product_attribute_id' => $unrelated->id, 'value' => 'Невалидна']);

        $this->qualityAssignment($parent, $ram, required: true, order: 1);
        $this->qualityAssignment($parent, $color, required: false, order: 2);
        $this->qualityAssignment($selectCategory, $panel, required: true);
        $this->qualityAssignment($multiselectCategory, $ports, required: true);

        $missingRequired = $this->specificationQualityReadyProduct($parent, 'Missing required');
        $this->qualityTextValue($missingRequired, $color, 'Черен');
        $needsData = $this->specificationQualityReadyProduct($parent, 'Needs data');
        $this->qualityTextValue($needsData, $ram, '16 GB');
        $inheritedMissing = $this->specificationQualityReadyProduct($child, 'Inherited missing');
        $this->qualityTextValue($inheritedMissing, $color, 'Сребрист');
        $invalidSelect = $this->specificationQualityReadyProduct($selectCategory, 'Invalid select');
        ProductAttributeValue::factory()->create([
            'product_id' => $invalidSelect->id,
            'product_attribute_id' => $panel->id,
            'attribute_value_id' => $wrong->id,
            'value_text' => null,
            'custom_value' => 'Невалидна',
        ]);
        $invalidMultiselect = $this->specificationQualityReadyProduct($multiselectCategory, 'Invalid multiselect');
        ProductAttributeValue::factory()->create([
            'product_id' => $invalidMultiselect->id,
            'product_attribute_id' => $ports->id,
            'value_json' => ['attribute_value_ids' => [$usb->id, $wrong->id]],
            'value_text' => null,
            'custom_value' => null,
        ]);
        $noTemplate = $this->specificationQualityReadyProduct($noTemplateCategory, 'No template');
        $noCategory = $this->specificationQualityReadyProduct(null, 'No category');
        $good = $this->specificationQualityReadyProduct($parent, 'Complete specification');
        $this->qualityTextValue($good, $ram, '32 GB');
        $this->qualityTextValue($good, $color, 'Черен');
        $unrelatedIssue = $this->specificationQualityReadyProduct($parent, 'Unrelated issue', ['ean' => null]);
        $this->qualityTextValue($unrelatedIssue, $ram, '32 GB');
        $this->qualityTextValue($unrelatedIssue, $color, 'Черен');
        $flagOnly = $this->specificationQualityReadyProduct($parent, 'Flag only');
        $this->qualityTextValue($flagOnly, $ram, '64 GB');
        $this->qualityTextValue($flagOnly, $color, 'Сив');
        $flag = ProductQualityFlag::query()->create([
            'code' => 'specification_queue_manual_review',
            'label_bg' => 'Ръчен преглед',
            'label_en' => 'Manual review',
            'severity' => ProductQualityFlag::SEVERITY_MEDIUM,
            'responsible_role' => User::ROLE_PRODUCT_EDITOR,
            'type' => ProductQualityFlag::TYPE_DATA,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        ProductQualityFlagAssignment::query()->create([
            'product_id' => $flagOnly->id,
            'product_quality_flag_id' => $flag->id,
            'status' => ProductQualityFlagAssignment::STATUS_ACTIVE,
        ]);

        return [
            'missing_required' => $missingRequired,
            'needs_data' => $needsData,
            'inherited_missing' => $inheritedMissing,
            'invalid_select' => $invalidSelect,
            'invalid_multiselect' => $invalidMultiselect,
            'no_template' => $noTemplate,
            'no_category' => $noCategory,
            'good' => $good,
            'unrelated_issue' => $unrelatedIssue,
            'flag_only' => $flagOnly,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function specificationQualityReadyProduct(?Category $category, string $name, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'category_id' => $category?->id,
            'name' => $name,
            'description' => str_repeat('Подробно продуктово описание за проверка на качеството. ', 3),
            'short_description' => 'Кратко описание с достатъчно подробности.',
            'meta_title' => $name,
            'meta_description' => 'Пълно SEO описание за продуктовата опашка.',
            'name_translations' => ['en' => $name],
            'description_translations' => ['en' => 'Detailed English product description.'],
            'meta_title_translations' => ['en' => $name],
            'specifications' => ['legacy' => 'preserved'],
        ], $overrides));

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => "products/specification-queue-{$product->id}.jpg",
            'alt_text' => $name,
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        return $product;
    }

    private function qualityAttribute(string $label, string $type): ProductAttribute
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

    private function qualityAssignment(
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

    private function qualityTextValue(Product $product, ProductAttribute $attribute, string $value): ProductAttributeValue
    {
        return ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'value_text' => $value,
            'custom_value' => $value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function protectedQualitySnapshot(): array
    {
        $tables = [
            'products',
            'categories',
            'brands',
            'product_images',
            'product_attributes',
            'attribute_values',
            'product_attribute_values',
            'category_product_attributes',
            'product_quality_flags',
            'product_quality_flag_assignments',
            'supplier_products',
            'product_supplier_offers',
            'users',
            'roles',
            'permissions',
        ];

        return collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ])->all();
    }
}
