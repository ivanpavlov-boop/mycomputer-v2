<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductDataQualityQueue\Pages\ListProductDataQualityQueue;
use App\Filament\Resources\ProductDataQualityQueue\ProductDataQualityQueueResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Models\User;
use App\Services\Products\ProductDataQualityScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Product Data Quality Queue');

        Livewire::test(ListProductDataQualityQueue::class)
            ->assertCanSeeTableRecords([$product])
            ->assertSee('Missing SEO');
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
            ->assertSee('Missing SEO');
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
            ->assertSee('Missing image')
            ->assertSee('Needs image review')
            ->assertSee('Needs data cleanup')
            ->assertSee('Flag count')
            ->assertSee('Hidden')
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
            ->assertSee('Missing EN translation');
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
}
