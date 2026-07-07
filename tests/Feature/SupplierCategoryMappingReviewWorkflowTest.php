<?php

namespace Tests\Feature;

use App\Filament\Resources\CanonicalProductFamilies\CanonicalProductFamilyResource;
use App\Filament\Resources\SupplierCategoryMappings\Pages\ListSupplierCategoryMappings;
use App\Filament\Resources\SupplierCategoryMappings\SupplierCategoryMappingResource;
use App\Models\AttributeValue;
use App\Models\CanonicalProductFamily;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierCategoryMappingReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_resource_exposes_triage_columns_filters_and_actions(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $family = $this->family('cables_adapters');
        $mapping = $this->mapping([
            'supplier_category_name' => 'Power & Cable',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);

        $component = Livewire::test(ListSupplierCategoryMappings::class);
        $table = $component->instance()->getTable();

        $this->assertSame('Таксономия', SupplierCategoryMappingResource::getNavigationGroup());
        $this->assertSame('Таксономия', CanonicalProductFamilyResource::getNavigationGroup());

        $columns = array_keys($table->getColumns());
        $filters = array_keys($table->getFilters());
        $actions = collect($table->getActions())
            ->map(fn ($action): string => $action->getName())
            ->all();

        foreach ([
            'supplier.company_name',
            'supplier_category_name',
            'supplier_category_slug',
            'supplier_category_path',
            'staged_product_count',
            'canonicalProductFamily.code',
            'targetCategory.name',
            'status',
            'confidence',
            'match_reason',
            'notes_indicator',
            'reviewed_at',
            'reviewer.name',
        ] as $column) {
            $this->assertContains($column, $columns);
        }

        foreach ([
            'status',
            'supplier',
            'canonicalProductFamily',
            'confidence',
            'pending_review',
            'without_canonical_family',
            'unknown_family',
            'without_target_category',
            'approved_without_target_category',
        ] as $filter) {
            $this->assertContains($filter, $filters);
        }

        foreach (['approve', 'reject', 'ignore', 'reset_pending', 'edit', 'delete'] as $action) {
            $this->assertContains($action, $actions);
        }

        foreach (['apply', 'sync', 'syncAll', 'moveProducts', 'createCategory'] as $forbiddenAction) {
            $this->assertNotContains($forbiddenAction, $actions);
        }

        $component
            ->assertTableActionVisible('approve', $mapping)
            ->assertTableActionEnabled('approve', $mapping)
            ->assertTableActionVisible('reject', $mapping)
            ->assertTableActionVisible('ignore', $mapping);
    }

    public function test_super_admin_can_review_mappings_without_mutating_catalog_data(): void
    {
        $reviewer = $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $family = $this->family('peripherals');
        $category = Category::factory()->create([
            'name' => 'Existing Peripherals',
            'slug' => 'existing-peripherals',
            'description' => 'Keep this description',
            'image_path' => 'categories/peripherals.jpg',
        ]);
        $product = Product::factory()->supplierPublished()->create([
            'category_id' => $category->id,
            'sku' => 'SUP-CAT-REVIEW-001',
        ]);
        $attribute = ProductAttribute::factory()->create([
            'code' => 'material',
            'slug' => 'material',
            'name_bg' => 'Материал',
        ]);
        $attributeValue = AttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'value' => 'Metal',
            'slug' => 'metal',
        ]);
        $assignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_required' => true,
        ]);
        $productValue = ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => $attributeValue->id,
            'value_text' => 'Metal',
            'custom_value' => 'Metal',
        ]);
        $supplierProduct = $this->supplierProduct($this->supplier(), 'Mice & Keyboards');

        $approve = $this->mapping([
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_category_name' => 'Mice & Keyboards',
            'canonical_product_family_id' => $family->id,
            'target_category_id' => null,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);
        $reject = $this->mapping([
            'supplier_category_name' => 'Wrong Supplier Bucket',
            'canonical_product_family_id' => $family->id,
        ]);
        $ignore = $this->mapping([
            'supplier_category_name' => 'Legacy Supplier Bucket',
            'canonical_product_family_id' => $family->id,
        ]);

        $counts = $this->protectedCounts();
        $snapshots = [
            'product' => $product->fresh()->only(['category_id', 'name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']),
            'supplierProduct' => $supplierProduct->fresh()->only(['category_name', 'name', 'supplier_sku', 'raw_data', 'status', 'updated_at']),
            'category' => $category->fresh()->only(['name', 'slug', 'description', 'image_path', 'updated_at']),
            'assignment' => $assignment->fresh()->only(['category_id', 'product_attribute_id', 'is_required', 'is_filterable', 'is_visible_on_product', 'is_comparable', 'sort_order', 'updated_at']),
            'attribute' => $attribute->fresh()->only(['code', 'name_bg', 'slug', 'type', 'updated_at']),
            'attributeValue' => $attributeValue->fresh()->only(['product_attribute_id', 'value', 'slug', 'updated_at']),
            'productValue' => $productValue->fresh()->only(['product_id', 'product_attribute_id', 'attribute_value_id', 'value_text', 'custom_value', 'updated_at']),
        ];

        $component = Livewire::test(ListSupplierCategoryMappings::class);

        $component
            ->callTableAction('approve', $approve)
            ->assertHasNoTableActionErrors();

        $component
            ->callTableAction('reject', $reject, ['notes' => 'Incorrect family candidate'])
            ->assertHasNoTableActionErrors();

        $component
            ->callTableAction('ignore', $ignore, ['notes' => 'Not useful for templates'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('supplier_category_mappings', [
            'id' => $approve->id,
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'target_category_id' => null,
        ]);
        $this->assertNotNull($approve->fresh()->reviewed_at);

        $this->assertDatabaseHas('supplier_category_mappings', [
            'id' => $reject->id,
            'status' => SupplierCategoryMapping::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'notes' => 'Incorrect family candidate',
        ]);

        $this->assertDatabaseHas('supplier_category_mappings', [
            'id' => $ignore->id,
            'status' => SupplierCategoryMapping::STATUS_IGNORED,
            'reviewed_by' => $reviewer->id,
            'notes' => 'Not useful for templates',
        ]);

        $this->assertSame($counts, $this->protectedCounts());
        $this->assertEquals($snapshots['product'], $product->fresh()->only(array_keys($snapshots['product'])));
        $this->assertEquals($snapshots['supplierProduct'], $supplierProduct->fresh()->only(array_keys($snapshots['supplierProduct'])));
        $this->assertEquals($snapshots['category'], $category->fresh()->only(array_keys($snapshots['category'])));
        $this->assertEquals($snapshots['assignment'], $assignment->fresh()->only(array_keys($snapshots['assignment'])));
        $this->assertEquals($snapshots['attribute'], $attribute->fresh()->only(array_keys($snapshots['attribute'])));
        $this->assertEquals($snapshots['attributeValue'], $attributeValue->fresh()->only(array_keys($snapshots['attributeValue'])));
        $this->assertEquals($snapshots['productValue'], $productValue->fresh()->only(array_keys($snapshots['productValue'])));
    }

    public function test_quick_approve_requires_known_family_but_not_future_category(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $known = $this->family('cables_adapters');
        $unknown = $this->family('unknown');
        $knownMapping = $this->mapping([
            'supplier_category_name' => 'Power & Cable',
            'canonical_product_family_id' => $known->id,
            'target_category_id' => null,
        ]);
        $unknownMapping = $this->mapping([
            'supplier_category_name' => 'Unclear Supplier Bucket',
            'canonical_product_family_id' => $unknown->id,
        ]);
        $unmapped = $this->mapping([
            'supplier_category_name' => 'No Family Yet',
            'canonical_product_family_id' => null,
        ]);

        $component = Livewire::test(ListSupplierCategoryMappings::class);

        $component
            ->assertTableActionEnabled('approve', $knownMapping)
            ->assertTableActionDisabled('approve', $unknownMapping)
            ->assertTableActionDisabled('approve', $unmapped);

        $this->assertTrue(SupplierCategoryMappingResource::approveMapping($knownMapping));
        $this->assertFalse(SupplierCategoryMappingResource::approveMapping($unknownMapping));
        $this->assertFalse(SupplierCategoryMappingResource::approveMapping($unmapped));

        $this->assertSame(SupplierCategoryMapping::STATUS_APPROVED, $knownMapping->fresh()->status);
        $this->assertNull($knownMapping->fresh()->target_category_id);
        $this->assertSame(SupplierCategoryMapping::STATUS_PENDING_REVIEW, $unknownMapping->fresh()->status);
        $this->assertSame(SupplierCategoryMapping::STATUS_PENDING_REVIEW, $unmapped->fresh()->status);
    }

    public function test_review_queue_prioritizes_pending_high_volume_mappings(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $supplier = $this->supplier();
        $family = $this->family('peripherals');

        $approvedHighVolume = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Approved High Volume',
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);
        $pendingLowVolume = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Pending Low Volume',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_LOW,
        ]);
        $pendingHighVolume = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Pending High Volume',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);

        foreach (range(1, 5) as $_) {
            $this->supplierProduct($supplier, 'Pending High Volume');
            $this->supplierProduct($supplier, 'Approved High Volume');
        }
        $this->supplierProduct($supplier, 'Pending Low Volume');

        Livewire::test(ListSupplierCategoryMappings::class)
            ->assertCanSeeTableRecords([
                $pendingHighVolume,
                $pendingLowVolume,
                $approvedHighVolume,
            ], inOrder: true);
    }

    public function test_viewer_auditor_can_view_but_cannot_mutate_review_records(): void
    {
        $family = $this->family('cables_adapters');
        $mapping = $this->mapping([
            'supplier_category_name' => 'Power & Cable',
            'canonical_product_family_id' => $family->id,
        ]);

        $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);

        $this->assertTrue(SupplierCategoryMappingResource::canViewAny());
        $this->assertTrue(SupplierCategoryMappingResource::canView($mapping));
        $this->assertFalse(SupplierCategoryMappingResource::canCreate());
        $this->assertFalse(SupplierCategoryMappingResource::canEdit($mapping));
        $this->assertFalse(SupplierCategoryMappingResource::canDelete($mapping));

        Livewire::test(ListSupplierCategoryMappings::class)
            ->assertCanSeeTableRecords([$mapping])
            ->assertTableActionHidden('approve', $mapping)
            ->assertTableActionHidden('reject', $mapping)
            ->assertTableActionHidden('ignore', $mapping)
            ->assertTableActionHidden('reset_pending', $mapping)
            ->assertTableActionHidden('edit', $mapping)
            ->assertTableActionHidden('delete', $mapping);

        $this->assertSame(SupplierCategoryMapping::STATUS_PENDING_REVIEW, $mapping->fresh()->status);
    }

    public function test_review_workflow_keeps_sync_flags_and_forbidden_surfaces_unchanged(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $family = $this->family('cables_adapters');
        $mapping = $this->mapping([
            'supplier_category_name' => 'Power & Cable',
            'canonical_product_family_id' => $family->id,
        ]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->callTableAction('approve', $mapping)
            ->assertHasNoTableActionErrors();

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->get('/cart')->assertNotFound();
        $this->get('/checkout')->assertNotFound();
    }

    private function supplier(): Supplier
    {
        return Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function mapping(array $attributes = []): SupplierCategoryMapping
    {
        return SupplierCategoryMapping::query()->create(array_merge([
            'supplier_category_name' => 'Supplier Category '.str()->random(6),
            'status' => SupplierCategoryMapping::STATUS_PENDING_REVIEW,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        ], $attributes));
    }

    private function family(string $code): CanonicalProductFamily
    {
        return CanonicalProductFamily::query()->create([
            'code' => $code,
            'name_bg' => str($code)->replace('_', ' ')->title()->toString(),
            'active' => true,
        ]);
    }

    private function supplierProduct(Supplier $supplier, ?string $categoryName): SupplierProduct
    {
        return SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-'.str()->random(8),
            'name' => 'Supplier staged product',
            'category_name' => $categoryName,
            'currency' => 'EUR',
            'raw_data' => ['category' => $categoryName],
            'payload_hash' => sha1((string) str()->uuid()),
            'received_at' => now(),
            'status' => 'new',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function protectedCounts(): array
    {
        return [
            'products' => Product::query()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'categories' => Category::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
        ];
    }

    private function actingAsRole(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }
}
