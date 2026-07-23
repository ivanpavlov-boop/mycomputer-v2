<?php

namespace Tests\Feature;

use App\Filament\Pages\CategoryGovernanceAudit;
use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Categories\CategoryGovernanceAuditService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryGovernanceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_reports_hierarchy_depth_paths_and_bounded_product_counts(): void
    {
        $root = $this->category('Компютри', 'computers', 10);
        $child = $this->category('Лаптопи', 'laptops', 20, $root);
        $grandchild = $this->category('Бизнес лаптопи', 'business-laptops', 30, $child);

        Product::factory()->create(['category_id' => $root->id]);
        Product::factory()->create([
            'category_id' => $child->id,
            'active' => false,
            'product_status' => 'draft',
            'workflow_status' => Product::WORKFLOW_DRAFT,
            'published_at' => null,
        ]);
        Product::factory()->create(['category_id' => $grandchild->id]);

        $snapshot = $this->service()->snapshot();
        $rows = collect($snapshot->categories)->keyBy('id');

        $this->assertSame(3, $snapshot->summary['all_including_deleted']);
        $this->assertSame(3, $snapshot->summary['not_deleted']);
        $this->assertSame(1, $snapshot->summary['root_categories']);
        $this->assertSame(2, $snapshot->summary['non_root_categories']);
        $this->assertSame(3, $snapshot->summary['maximum_depth']);
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $snapshot->depthDistribution);
        $this->assertSame('Компютри › Лаптопи › Бизнес лаптопи', $rows[$grandchild->id]['full_path']);
        $this->assertSame(2, $rows[$root->id]['descendant_count']);
        $this->assertSame(1, $rows[$child->id]['descendant_count']);
        $this->assertSame(1, $rows[$root->id]['direct_product_count']);
        $this->assertSame(1, $rows[$root->id]['published_direct_product_count']);
        $this->assertSame(2, $rows[$root->id]['published_subtree_product_count']);
        $this->assertSame(1, $rows[$child->id]['direct_product_count']);
        $this->assertSame(0, $rows[$child->id]['published_direct_product_count']);
        $this->assertSame('/c/business-laptops', $rows[$grandchild->id]['public_url']);
    }

    public function test_snapshot_detects_empty_categories_without_mutating_them(): void
    {
        $category = $this->category('Празна категория', 'empty-category', 1);
        $updatedAt = $category->updated_at?->toJSON();

        $row = collect($this->service()->snapshot()->categories)->firstWhere('id', $category->id);

        $this->assertContains('no_direct_products', $row['issue_codes']);
        $this->assertContains('no_published_direct_products', $row['issue_codes']);
        $this->assertContains('no_published_products_in_subtree', $row['issue_codes']);
        $this->assertSame('warning', $row['highest_severity']);
        $this->assertSame($updatedAt, $category->fresh()->updated_at?->toJSON());
    }

    public function test_snapshot_detects_cycles_orphans_and_unreachable_active_categories(): void
    {
        $cycleA = $this->category('Цикъл A', 'cycle-a', 1);
        $cycleB = $this->category('Цикъл B', 'cycle-b', 2);
        DB::table('categories')->where('id', $cycleA->id)->update(['parent_id' => $cycleB->id]);
        DB::table('categories')->where('id', $cycleB->id)->update(['parent_id' => $cycleA->id]);

        $orphan = $this->memoryCategory(999, 'Сирак', 'orphan', 3, 999999);
        $categories = Category::withTrashed()->get()->push($orphan);
        $snapshot = $this->service()->analyze($categories);
        $rows = collect($snapshot->categories)->keyBy('id');

        $this->assertContains('cycle', $rows[$cycleA->id]['issue_codes']);
        $this->assertContains('cycle', $rows[$cycleB->id]['issue_codes']);
        $this->assertContains('unreachable_from_root', $rows[$cycleA->id]['issue_codes']);
        $this->assertContains('orphan_parent', $rows[$orphan->id]['issue_codes']);
        $this->assertContains('unreachable_from_root', $rows[$orphan->id]['issue_codes']);
        $this->assertSame(3, $snapshot->summary['unreachable_active_categories']);
    }

    public function test_snapshot_detects_active_categories_below_inactive_or_deleted_ancestors(): void
    {
        $inactiveParent = $this->category('Неактивен родител', 'inactive-parent', 1, null, false);
        $inactiveChild = $this->category('Активно дете', 'active-child', 1, $inactiveParent);
        $deletedParent = $this->category('Изтрит родител', 'deleted-parent', 2);
        $deletedChild = $this->category('Дете под изтрит', 'deleted-child', 1, $deletedParent);
        $deletedParent->delete();

        $rows = collect($this->service()->snapshot()->categories)->keyBy('id');

        $this->assertContains('active_under_inactive_parent', $rows[$inactiveChild->id]['issue_codes']);
        $this->assertContains('unreachable_from_root', $rows[$inactiveChild->id]['issue_codes']);
        $this->assertContains('active_under_deleted_parent', $rows[$deletedChild->id]['issue_codes']);
        $this->assertContains('unreachable_from_root', $rows[$deletedChild->id]['issue_codes']);
    }

    public function test_deterministic_analysis_detects_duplicate_missing_and_naming_issues(): void
    {
        $categories = new Collection([
            $this->memoryCategory(1, '  Same   Name ', 'same-slug', 0),
            $this->memoryCategory(2, 'same name', 'same-slug', 0),
            $this->memoryCategory(3, 'Trailing quote"', 'trailing-quote', 3),
            $this->memoryCategory(4, '', '', 4),
            $this->memoryCategory(5, 'Latin Only', 'latin-only', 5),
        ]);

        $snapshot = $this->service()->analyze(
            $categories,
            generatedAt: CarbonImmutable::parse('2026-07-23 12:00:00', 'UTC'),
        );
        $rows = collect($snapshot->categories)->keyBy('id');

        $this->assertContains('duplicate_slug', $rows[1]['issue_codes']);
        $this->assertContains('duplicate_slug', $rows[2]['issue_codes']);
        $this->assertContains('duplicate_normalized_name', $rows[1]['issue_codes']);
        $this->assertContains('duplicate_normalized_name', $rows[2]['issue_codes']);
        $this->assertContains('sibling_sort_order_collision', $rows[1]['issue_codes']);
        $this->assertContains('zero_sort_order', $rows[1]['issue_codes']);
        $this->assertContains('suspicious_name_punctuation', $rows[3]['issue_codes']);
        $this->assertContains('missing_name', $rows[4]['issue_codes']);
        $this->assertContains('missing_slug', $rows[4]['issue_codes']);
        $this->assertContains('possible_latin_only_public_name', $rows[5]['issue_codes']);
        $this->assertContains('missing_explicit_bg_translation', $rows[5]['issue_codes']);
        $this->assertSame('critical', $rows[1]['highest_severity']);
        $this->assertSame('2026-07-23T12:00:00+00:00', $snapshot->generatedAt);
    }

    public function test_deep_valid_hierarchy_and_zero_category_database_are_safe(): void
    {
        $empty = $this->service()->snapshot();

        $this->assertSame(0, $empty->summary['all_including_deleted']);
        $this->assertSame(0, $empty->summary['maximum_depth']);
        $this->assertSame([], $empty->categories);

        $parent = null;

        for ($depth = 1; $depth <= 12; $depth++) {
            $parent = $this->category("Ниво {$depth}", "level-{$depth}", $depth, $parent);
        }

        $deep = $this->service()->snapshot();

        $this->assertSame(12, $deep->summary['maximum_depth']);
        $this->assertSame(1, $deep->depthDistribution[12]);
        $this->assertSame(11, collect($deep->categories)->firstWhere('depth', 1)['descendant_count']);
    }

    public function test_snapshot_uses_three_bounded_queries(): void
    {
        $root = $this->category('Root', 'root', 1);
        $child = $this->category('Child', 'child', 1, $root);
        Product::factory()->count(3)->create(['category_id' => $child->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service()->snapshot();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, count($queries));
    }

    public function test_snapshot_and_admin_render_do_not_write_catalog_or_staging_data(): void
    {
        $category = $this->category('Read only', 'read-only', 1);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $supplier = Supplier::factory()->create();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'AUDIT-STAGING-001',
            'name' => 'Audit staging product',
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => 'audit-staging-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);
        $before = [
            'categories' => Category::withTrashed()->count(),
            'products' => Product::withTrashed()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'category_updated_at' => $category->updated_at?->toJSON(),
            'product_updated_at' => $product->updated_at?->toJSON(),
            'supplier_product_updated_at' => $supplierProduct->updated_at?->toJSON(),
        ];

        $this->service()->snapshot();
        $this->actingAsSuperAdmin();
        $this->get(CategoryGovernanceAudit::getUrl())->assertOk();

        $this->assertSame($before['categories'], Category::withTrashed()->count());
        $this->assertSame($before['products'], Product::withTrashed()->count());
        $this->assertSame($before['supplier_products'], SupplierProduct::query()->count());
        $this->assertSame($before['category_updated_at'], $category->fresh()->updated_at?->toJSON());
        $this->assertSame($before['product_updated_at'], $product->fresh()->updated_at?->toJSON());
        $this->assertSame($before['supplier_product_updated_at'], $supplierProduct->fresh()->updated_at?->toJSON());
    }

    public function test_authorized_admins_can_view_the_read_only_page_without_broadening_viewer_access(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $category = $this->category('Категория за одит', 'audit-category', 1);

        $superAdmin = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($superAdmin)
            ->get(CategoryGovernanceAudit::getUrl())
            ->assertOk()
            ->assertSee('Одит на категориите')
            ->assertSee('Категория за одит')
            ->assertSee('Директни продукти')
            ->assertSee('Ръчни препоръки')
            ->assertDontSee('Изтрий')
            ->assertDontSee('Възстанови')
            ->assertDontSee('Обедини');

        $catalogManager = $this->user(User::ROLE_CATALOG_MANAGER);
        $this->actingAs($catalogManager)
            ->get(CategoryGovernanceAudit::getUrl())
            ->assertOk();

        $viewer = $this->user(User::ROLE_VIEWER_AUDITOR);
        $this->actingAs($viewer)
            ->get(CategoryGovernanceAudit::getUrl())
            ->assertForbidden();

        $this->actingAs($superAdmin);
        $this->assertTrue(CategoryGovernanceAudit::canAccess());
        $this->assertTrue(CategoryResource::canViewAny());
        $this->assertTrue($superAdmin->isActiveAdminAccount());
        $this->assertSame(1, User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('is_active', true)->count());
        $this->assertFalse($viewer->can('viewAny', Category::class));
    }

    public function test_page_filters_search_and_sorting_are_read_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs($this->user(User::ROLE_CATALOG_MANAGER));
        $this->category('Алфа категория', 'alpha-category', 20);
        $this->category('Бета категория', 'beta-category', 10);

        Livewire::test(CategoryGovernanceAudit::class)
            ->assertSee('Алфа категория')
            ->assertSee('Бета категория')
            ->set('search', 'Алфа')
            ->assertSee('Алфа категория')
            ->assertDontSee('Бета категория')
            ->set('search', '')
            ->set('zeroSortOrder', 'no')
            ->set('sortBy', 'sort_order')
            ->set('sortDirection', 'desc')
            ->assertSee('Алфа категория')
            ->call('resetAuditFilters')
            ->assertSet('sortBy', 'path')
            ->assertSet('sortDirection', 'asc');
    }

    private function service(): CategoryGovernanceAuditService
    {
        return app(CategoryGovernanceAuditService::class);
    }

    private function category(
        string $name,
        string $slug,
        int $sortOrder,
        ?Category $parent = null,
        bool $active = true,
    ): Category {
        return Category::factory()->create([
            'parent_id' => $parent?->id,
            'name' => $name,
            'name_translations' => ['bg' => $name],
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'is_active' => $active,
        ]);
    }

    private function memoryCategory(
        int $id,
        string $name,
        string $slug,
        int $sortOrder,
        ?int $parentId = null,
    ): Category {
        $category = new Category;
        $category->forceFill([
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $name,
            'name_translations' => null,
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'deleted_at' => null,
        ]);

        return $category;
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($user);

        return $user;
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
