<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AvailabilityStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Support\Enums\FontFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTableUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_compact_default_columns_are_ordered_and_secondary_columns_are_hidden_by_default(): void
    {
        $this->actingAsSuperAdmin();

        $table = Livewire::test(ListProducts::class)->instance()->getTable();

        $this->assertSame([
            'thumbnail',
            'sku',
            'name',
            'category.name',
            'brand.name',
            'price',
            'supplier.company_name',
            'workflow_status',
            'availabilityStatus.name',
            'storefront',
        ], array_slice(array_keys($table->getColumns()), 0, 10));

        foreach ([
            'active_quality_flag_assignments_count',
            'specification_quality',
            'promo_price',
            'quantity',
            'reserved_quantity',
            'stock_status',
            'manual_override',
            'active',
            'featured',
            'new_product',
            'bestseller',
            'updated_at',
        ] as $columnName) {
            $column = $table->getColumn($columnName);

            $this->assertTrue($column->isToggleable(), "{$columnName} should be toggleable.");
            $this->assertTrue($column->isToggledHiddenByDefault(), "{$columnName} should be hidden by default.");
        }
    }

    public function test_sku_copying_and_compact_workflow_status_use_native_accessible_table_features(): void
    {
        $this->actingAsSuperAdmin();

        $workflowProducts = collect([
            Product::WORKFLOW_DRAFT,
            Product::WORKFLOW_PENDING_REVIEW,
            Product::WORKFLOW_CHANGES_REQUESTED,
            Product::WORKFLOW_APPROVED,
            Product::WORKFLOW_PUBLISHED,
        ])->mapWithKeys(fn (string $workflowStatus): array => [
            $workflowStatus => Product::factory()->create([
                'sku' => $workflowStatus === Product::WORKFLOW_DRAFT ? 'COMPACT-SKU-001' : "COMPACT-SKU-{$workflowStatus}",
                'workflow_status' => $workflowStatus,
            ]),
        ]);
        /** @var Product $product */
        $product = $workflowProducts->get(Product::WORKFLOW_DRAFT);

        $component = Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('sku', 'COMPACT-SKU-001', $product)
            ->assertTableColumnStateSet('workflow_status', '●', $product);

        $skuColumn = $component->instance()->getTable()->getColumn('sku');
        $skuColumn->record($product);

        $this->assertTrue($skuColumn->isCopyable($skuColumn->getState()));
        $this->assertSame('COMPACT-SKU-001', $skuColumn->getCopyableState($skuColumn->getState()));
        $this->assertSame('SKU е копиран', $skuColumn->getCopyMessage($skuColumn->getState()));
        $this->assertSame(1500, $skuColumn->getCopyMessageDuration($skuColumn->getState()));
        $this->assertSame(FontFamily::Mono, $skuColumn->getFontFamily());
        $this->assertSame('COMPACT-SKU-001', $product->fresh()->sku);

        $statusColumn = $component->instance()->getTable()->getColumn('workflow_status');
        $this->assertSame('Статус', $statusColumn->getLabel());
        $this->assertTrue($statusColumn->isSortable());

        foreach ($workflowProducts as $workflowStatus => $workflowProduct) {
            $component
                ->assertTableColumnStateSet('workflow_status', '●', $workflowProduct)
                ->assertTableColumnHasExtraAttributes('workflow_status', [
                    'aria-label' => Product::workflowStatusLabel($workflowStatus),
                    'role' => 'img',
                ], $workflowProduct);

            $statusColumn->record($workflowProduct);

            $this->assertSame('●', $statusColumn->getState());
            $this->assertSame(Product::workflowStatusColor($workflowStatus), $statusColumn->getColor($statusColumn->getState()));
            $this->assertSame(Product::workflowStatusLabel($workflowStatus), $statusColumn->getTooltip());
        }
    }

    public function test_supplier_and_availability_context_are_read_only_and_filterable(): void
    {
        $this->actingAsSuperAdmin();

        $supplier = Supplier::factory()->create(['company_name' => 'Компактен вносител']);
        $availability = AvailabilityStatus::query()->create([
            'code' => Product::STOCK_STATUS_IN_STOCK,
            'name' => 'In Stock',
            'color' => 'green',
            'icon' => 'check',
            'badge_style' => 'soft',
            'allow_purchase' => true,
            'show_stock_quantity' => true,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $withSupplier = Product::factory()->create([
            'supplier_id' => $supplier->id,
            'availability_status_id' => $availability->id,
            'quantity' => 12,
        ]);
        $withoutSupplier = Product::factory()->create([
            'supplier_id' => null,
            'availability_status_id' => null,
            'stock_status' => Product::STOCK_STATUS_LIMITED_STOCK,
            'quantity' => 3,
        ]);

        Livewire::test(ListProducts::class)
            ->assertTableFilterExists('supplier')
            ->assertTableColumnStateSet('supplier.company_name', 'Компактен вносител', $withSupplier)
            ->assertTableColumnFormattedStateSet('availabilityStatus.name', 'В наличност · 12', $withSupplier)
            ->assertTableColumnFormattedStateSet('availabilityStatus.name', 'Ограничена наличност · 3', $withoutSupplier)
            ->filterTable('supplier', $supplier)
            ->assertCanSeeTableRecords([$withSupplier])
            ->assertCanNotSeeTableRecords([$withoutSupplier]);

        $this->assertSame('—', Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getColumn('supplier.company_name')
            ->getPlaceholder());

        $withSupplier->refresh();
        $withoutSupplier->refresh();

        $this->assertSame($supplier->id, $withSupplier->supplier_id);
        $this->assertNull($withoutSupplier->supplier_id);
        $this->assertSame(12, $withSupplier->quantity);
        $this->assertSame(3, $withoutSupplier->quantity);
    }

    public function test_table_uses_existing_edit_urls_and_storefront_links_stay_limited_to_public_products(): void
    {
        $this->actingAsSuperAdmin();

        config()->set('app.url', 'https://storefront.example.test');

        $public = Product::factory()->create(['slug' => 'compact-public-product']);
        $nonPublic = Product::factory()->manualDraft()->create(['slug' => 'compact-draft-product']);
        $component = Livewire::test(ListProducts::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('viewStorefront')
            ->assertTableColumnStateSet('storefront', 'Виж в сайта', $public)
            ->assertTableColumnStateSet('storefront', '—', $nonPublic);
        $table = $component->instance()->getTable();

        $this->assertSame(ProductResource::getUrl('edit', ['record' => $public]), $table->getRecordUrl($public));

        $storefrontColumn = $table->getColumn('storefront');
        $storefrontColumn->record($public);
        $this->assertSame($public->storefrontUrl(), $storefrontColumn->getUrl());
        $this->assertTrue($storefrontColumn->shouldOpenUrlInNewTab());
        $this->assertFalse($storefrontColumn->isClickDisabled());

        $storefrontColumn->record($nonPublic);
        $this->assertNull($storefrontColumn->getUrl());
        $this->assertTrue($storefrontColumn->isClickDisabled());
    }

    public function test_viewer_auditor_does_not_receive_product_edit_navigation(): void
    {
        $product = Product::factory()->create();
        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER_AUDITOR,
            'is_active' => true,
        ]);
        $viewer->assignRole(User::ROLE_VIEWER_AUDITOR);
        $this->actingAs($viewer);

        $this->assertFalse(ProductResource::canEdit($product));
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
    }

    public function test_displayed_relationships_are_eager_loaded_and_loaded_category_visibility_matches_the_query_rule(): void
    {
        $this->actingAsSuperAdmin();

        $product = Product::factory()->create(['slug' => 'loaded-category-product']);
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $inactiveProduct = Product::factory()->create(['category_id' => $inactiveCategory->id]);
        $deletedCategory = Category::factory()->create();
        $missingCategoryProduct = Product::factory()->create(['category_id' => $deletedCategory->id]);
        $deletedCategory->delete();

        $record = Livewire::test(ListProducts::class)
            ->instance()
            ->getTableRecord((string) $product->getKey());

        foreach (['thumbnailImage', 'category', 'brand', 'supplier', 'availabilityStatus'] as $relation) {
            $this->assertTrue($record->relationLoaded($relation), "{$relation} should be eager loaded for the table.");
        }

        $this->assertArrayHasKey('active_quality_flag_assignments_count', $record->getAttributes());

        $loadedProduct = Product::query()->with('category')->findOrFail($product->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->assertTrue($loadedProduct->isPubliclyVisible());
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($product->fresh()->isPubliclyVisible());

        foreach ([$inactiveProduct, $missingCategoryProduct] as $nonPublicProduct) {
            $loadedNonPublicProduct = Product::query()->with('category')->findOrFail($nonPublicProduct->id);

            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                $this->assertFalse($loadedNonPublicProduct->isPubliclyVisible());
                $this->assertCount(0, DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }

            $this->assertFalse($nonPublicProduct->fresh()->isPubliclyVisible());
        }
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $user->assignRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($user);

        return $user;
    }
}
