<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierCategoryMappings\Pages\EditSupplierCategoryMapping;
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

    public function test_edit_page_review_action_labels_and_notifications_are_readable_bulgarian(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $mapping = $this->mapping([
            'canonical_product_family_id' => $this->family('peripherals')->id,
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
        ]);

        $actions = collect(Livewire::test(EditSupplierCategoryMapping::class, ['record' => $mapping->getKey()])
            ->instance()
            ->getCachedHeaderActions())
            ->keyBy(fn ($action): string => $action->getName());

        $expected = [
            'approve' => ['Одобри', 'Mapping-ът е одобрен.'],
            'reject' => ['Отхвърли', 'Mapping-ът е отхвърлен.'],
            'ignore' => ['Игнорирай', 'Mapping-ът е игнориран.'],
            'reset_pending' => ['Върни за преглед', 'Mapping-ът е върнат за преглед.'],
        ];

        foreach ($expected as $actionName => [$label, $successTitle]) {
            $this->assertTrue($actions->has($actionName));
            $this->assertSame($label, $actions[$actionName]->getLabel());
            $this->assertSame($successTitle, $actions[$actionName]->getSuccessNotificationTitle());
            $this->assertStringNotContainsString('Р ', $actions[$actionName]->getLabel());
            $this->assertStringNotContainsString('Гђ', $actions[$actionName]->getLabel());
            $this->assertStringNotContainsString('Г‘', $actions[$actionName]->getLabel());
        }

        foreach (['apply', 'syncAll', 'moveProducts', 'createCategory', 'updateProductCategories'] as $forbiddenAction) {
            $this->assertFalse($actions->has($forbiddenAction));
        }
    }

    public function test_status_filter_uses_canonical_status_values_with_bulgarian_labels(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $supplier = $this->supplier();
        $family = $this->family('peripherals');
        $category = Category::factory()->create();
        $product = Product::factory()->supplierPublished()->create(['category_id' => $category->id]);
        $attribute = ProductAttribute::factory()->create(['code' => 'filter_guard']);
        $attributeValue = AttributeValue::factory()->create(['product_attribute_id' => $attribute->id]);
        $assignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
        ]);
        $productValue = ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => $attributeValue->id,
        ]);

        $pending = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Pending Cable Mapping',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        ]);
        $approved = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Approved Cable High Volume',
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_LOW,
        ]);
        $approvedHighConfidence = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Approved Cable High Confidence',
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);
        $rejected = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Rejected Mouse Mapping',
            'status' => SupplierCategoryMapping::STATUS_REJECTED,
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        ]);
        $ignored = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Ignored Mouse Mapping',
            'status' => SupplierCategoryMapping::STATUS_IGNORED,
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_LOW,
        ]);

        $this->supplierProducts($supplier, 'Approved Cable High Volume', 5);
        $this->supplierProducts($supplier, 'Approved Cable High Confidence', 1);
        $this->supplierProducts($supplier, 'Pending Cable Mapping', 2);
        $this->supplierProducts($supplier, 'Rejected Mouse Mapping', 3);
        $this->supplierProducts($supplier, 'Ignored Mouse Mapping', 4);

        $counts = $this->protectedCounts();
        $mappingStatuses = $this->mappingStatuses([$pending, $approved, $approvedHighConfidence, $rejected, $ignored]);
        $snapshots = [
            'product' => $product->fresh()->only(['category_id', 'workflow_status', 'product_status', 'active', 'updated_at']),
            'category' => $category->fresh()->only(['name', 'slug', 'description', 'image_path', 'updated_at']),
            'assignment' => $assignment->fresh()->only(['category_id', 'product_attribute_id', 'is_required', 'is_filterable', 'is_visible_on_product', 'is_comparable', 'sort_order', 'updated_at']),
            'attribute' => $attribute->fresh()->only(['code', 'name_bg', 'slug', 'type', 'updated_at']),
            'attributeValue' => $attributeValue->fresh()->only(['product_attribute_id', 'value', 'slug', 'updated_at']),
            'productValue' => $productValue->fresh()->only(['product_id', 'product_attribute_id', 'attribute_value_id', 'value_text', 'custom_value', 'updated_at']),
        ];

        $this->assertSame([
            SupplierCategoryMapping::STATUS_PENDING_REVIEW => 'За преглед',
            SupplierCategoryMapping::STATUS_APPROVED => 'Одобрено',
            SupplierCategoryMapping::STATUS_REJECTED => 'Отхвърлено',
            SupplierCategoryMapping::STATUS_IGNORED => 'Игнорирано',
        ], SupplierCategoryMappingResource::statusOptions());
        $this->assertArrayNotHasKey('Одобрено', SupplierCategoryMappingResource::statusOptions());

        foreach (SupplierCategoryMappingResource::statusOptions() as $status => $label) {
            $this->assertContains($status, [
                SupplierCategoryMapping::STATUS_PENDING_REVIEW,
                SupplierCategoryMapping::STATUS_APPROVED,
                SupplierCategoryMapping::STATUS_REJECTED,
                SupplierCategoryMapping::STATUS_IGNORED,
            ]);
            $this->assertStringNotContainsString('Р—', $label);
            $this->assertStringNotContainsString('Рћ', $label);
            $this->assertStringNotContainsString('Р', $label);
        }

        Livewire::test(ListSupplierCategoryMappings::class)
            ->assertTableFilterExists('status', fn ($filter): bool => $filter->getAttribute() === 'status');

        Livewire::test(ListSupplierCategoryMappings::class)
            ->filterTable('status', SupplierCategoryMapping::STATUS_PENDING_REVIEW)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved, $approvedHighConfidence, $rejected, $ignored])
            ->filterTable('status', SupplierCategoryMapping::STATUS_APPROVED)
            ->assertCanSeeTableRecords([$approved, $approvedHighConfidence])
            ->assertCanNotSeeTableRecords([$pending, $rejected, $ignored])
            ->filterTable('status', SupplierCategoryMapping::STATUS_REJECTED)
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$pending, $approved, $approvedHighConfidence, $ignored])
            ->filterTable('status', SupplierCategoryMapping::STATUS_IGNORED)
            ->assertCanSeeTableRecords([$ignored])
            ->assertCanNotSeeTableRecords([$pending, $approved, $approvedHighConfidence, $rejected])
            ->removeTableFilter('status')
            ->assertCanSeeTableRecords([$pending, $approved, $approvedHighConfidence, $rejected, $ignored]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->set('tableFilters.status', ['values' => [SupplierCategoryMapping::STATUS_REJECTED]])
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$pending, $approved, $approvedHighConfidence, $ignored]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->filterTable('status', [SupplierCategoryMapping::STATUS_IGNORED])
            ->assertCanSeeTableRecords([$ignored])
            ->assertCanNotSeeTableRecords([$pending, $approved, $approvedHighConfidence, $rejected]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->filterTable('status', SupplierCategoryMapping::STATUS_APPROVED)
            ->searchTable('High Confidence')
            ->assertCanSeeTableRecords([$approvedHighConfidence])
            ->assertCanNotSeeTableRecords([$pending, $approved, $rejected, $ignored]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->filterTable('status', SupplierCategoryMapping::STATUS_APPROVED)
            ->sortTable('staged_product_count', 'desc')
            ->assertCanSeeTableRecords([$approved, $approvedHighConfidence], inOrder: true)
            ->assertCanNotSeeTableRecords([$pending, $rejected, $ignored])
            ->sortTable('confidence', 'desc')
            ->assertCanSeeTableRecords([$approvedHighConfidence, $approved], inOrder: true)
            ->assertCanNotSeeTableRecords([$pending, $rejected, $ignored]);

        $this->assertSame($mappingStatuses, $this->mappingStatuses([$pending, $approved, $approvedHighConfidence, $rejected, $ignored]));
        $this->assertSame($counts, $this->protectedCounts());
        $this->assertEquals($snapshots['product'], $product->fresh()->only(array_keys($snapshots['product'])));
        $this->assertEquals($snapshots['category'], $category->fresh()->only(array_keys($snapshots['category'])));
        $this->assertEquals($snapshots['assignment'], $assignment->fresh()->only(array_keys($snapshots['assignment'])));
        $this->assertEquals($snapshots['attribute'], $attribute->fresh()->only(array_keys($snapshots['attribute'])));
        $this->assertEquals($snapshots['attributeValue'], $attributeValue->fresh()->only(array_keys($snapshots['attributeValue'])));
        $this->assertEquals($snapshots['productValue'], $productValue->fresh()->only(array_keys($snapshots['productValue'])));
    }

    public function test_status_column_sorts_by_workflow_order_without_filtering_or_mutation(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $supplier = $this->supplier();
        $family = $this->family('peripherals');

        $pendingOne = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Pending Cable One',
            'canonical_product_family_id' => $family->id,
        ]);
        $pendingTwo = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Pending Dock Two',
            'canonical_product_family_id' => $family->id,
        ]);
        $pendingThree = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Pending Hub Three',
            'canonical_product_family_id' => $family->id,
        ]);
        $approvedOne = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Approved Cable One',
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_LOW,
        ]);
        $approvedTwo = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Approved Cable Two',
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);
        $rejected = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Rejected Cable',
            'status' => SupplierCategoryMapping::STATUS_REJECTED,
            'canonical_product_family_id' => $family->id,
        ]);
        $ignored = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Ignored Cable',
            'status' => SupplierCategoryMapping::STATUS_IGNORED,
            'canonical_product_family_id' => $family->id,
        ]);
        $unknown = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Unknown Cable',
            'status' => 'needs_triage',
            'canonical_product_family_id' => $family->id,
        ]);

        $this->supplierProducts($supplier, 'Pending Cable One', 3);
        $this->supplierProducts($supplier, 'Pending Dock Two', 1);
        $this->supplierProducts($supplier, 'Pending Hub Three', 2);
        $this->supplierProducts($supplier, 'Approved Cable One', 2);
        $this->supplierProducts($supplier, 'Approved Cable Two', 1);
        $this->supplierProducts($supplier, 'Rejected Cable', 5);
        $this->supplierProducts($supplier, 'Ignored Cable', 4);
        $this->supplierProducts($supplier, 'Unknown Cable', 6);

        $allMappings = [$pendingOne, $pendingTwo, $pendingThree, $approvedOne, $approvedTwo, $rejected, $ignored, $unknown];
        $counts = $this->protectedCounts();
        $mappingStatuses = $this->mappingStatuses($allMappings);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->sortTable('status')
            ->assertCanSeeTableRecords([
                $approvedOne,
                $approvedTwo,
                $pendingOne,
                $pendingTwo,
                $pendingThree,
                $rejected,
                $ignored,
                $unknown,
            ], inOrder: true);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->sortTable('status', 'desc')
            ->assertCanSeeTableRecords([
                $ignored,
                $rejected,
                $pendingOne,
                $pendingTwo,
                $pendingThree,
                $approvedOne,
                $approvedTwo,
                $unknown,
            ], inOrder: true);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->searchTable('Cable')
            ->sortTable('status')
            ->assertCanSeeTableRecords([
                $approvedOne,
                $approvedTwo,
                $pendingOne,
                $rejected,
                $ignored,
                $unknown,
            ], inOrder: true)
            ->assertCanNotSeeTableRecords([$pendingTwo, $pendingThree]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->filterTable('status', SupplierCategoryMapping::STATUS_PENDING_REVIEW)
            ->sortTable('status')
            ->assertCanSeeTableRecords([$pendingOne, $pendingTwo, $pendingThree], inOrder: true)
            ->assertCanNotSeeTableRecords([$approvedOne, $approvedTwo, $rejected, $ignored, $unknown]);

        $this->assertSame($mappingStatuses, $this->mappingStatuses($allMappings));
        $this->assertSame($counts, $this->protectedCounts());
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
        $this->assertNotNull($reject->fresh()->reviewed_at);

        $this->assertDatabaseHas('supplier_category_mappings', [
            'id' => $ignore->id,
            'status' => SupplierCategoryMapping::STATUS_IGNORED,
            'reviewed_by' => $reviewer->id,
            'notes' => 'Not useful for templates',
        ]);
        $this->assertNotNull($ignore->fresh()->reviewed_at);

        $this->assertSame($counts, $this->protectedCounts());
        $this->assertEquals($snapshots['product'], $product->fresh()->only(array_keys($snapshots['product'])));
        $this->assertEquals($snapshots['supplierProduct'], $supplierProduct->fresh()->only(array_keys($snapshots['supplierProduct'])));
        $this->assertEquals($snapshots['category'], $category->fresh()->only(array_keys($snapshots['category'])));
        $this->assertEquals($snapshots['assignment'], $assignment->fresh()->only(array_keys($snapshots['assignment'])));
        $this->assertEquals($snapshots['attribute'], $attribute->fresh()->only(array_keys($snapshots['attribute'])));
        $this->assertEquals($snapshots['attributeValue'], $attributeValue->fresh()->only(array_keys($snapshots['attributeValue'])));
        $this->assertEquals($snapshots['productValue'], $productValue->fresh()->only(array_keys($snapshots['productValue'])));
    }

    public function test_edit_page_review_actions_redirect_to_index_without_mutating_catalog_data(): void
    {
        $reviewer = $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $family = $this->family('peripherals');
        $category = Category::factory()->create([
            'name' => 'Existing Review Category',
            'slug' => 'existing-review-category',
            'description' => 'Protected description',
            'image_path' => 'categories/protected.jpg',
        ]);
        $product = Product::factory()->supplierPublished()->create([
            'category_id' => $category->id,
            'sku' => 'SUP-CAT-REDIRECT-001',
        ]);
        $attribute = ProductAttribute::factory()->create([
            'code' => 'protected_attribute',
            'slug' => 'protected-attribute',
            'name_bg' => 'Protected attribute',
        ]);
        $attributeValue = AttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'value' => 'Protected value',
            'slug' => 'protected-value',
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
            'value_text' => 'Protected value',
            'custom_value' => 'Protected value',
        ]);
        $supplierProduct = $this->supplierProduct($this->supplier(), 'Protected Supplier Category');

        $approve = $this->mapping([
            'supplier_category_name' => 'Approve Redirect',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);
        $reject = $this->mapping([
            'supplier_category_name' => 'Reject Redirect',
            'canonical_product_family_id' => $family->id,
        ]);
        $ignore = $this->mapping([
            'supplier_category_name' => 'Ignore Redirect',
            'canonical_product_family_id' => $family->id,
        ]);
        $reset = $this->mapping([
            'supplier_category_name' => 'Reset Redirect',
            'canonical_product_family_id' => $family->id,
            'status' => SupplierCategoryMapping::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
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

        Livewire::test(EditSupplierCategoryMapping::class, ['record' => $approve->getKey()])
            ->callAction('approve')
            ->assertHasNoActionErrors()
            ->assertRedirect(SupplierCategoryMappingResource::getUrl('index'));

        Livewire::test(EditSupplierCategoryMapping::class, ['record' => $reject->getKey()])
            ->callAction('reject', ['notes' => 'Wrong candidate'])
            ->assertHasNoActionErrors()
            ->assertRedirect(SupplierCategoryMappingResource::getUrl('index'));

        Livewire::test(EditSupplierCategoryMapping::class, ['record' => $ignore->getKey()])
            ->callAction('ignore', ['notes' => 'Irrelevant candidate'])
            ->assertHasNoActionErrors()
            ->assertRedirect(SupplierCategoryMappingResource::getUrl('index'));

        Livewire::test(EditSupplierCategoryMapping::class, ['record' => $reset->getKey()])
            ->callAction('reset_pending')
            ->assertHasNoActionErrors()
            ->assertRedirect(SupplierCategoryMappingResource::getUrl('index'));

        $this->assertSame(SupplierCategoryMapping::STATUS_APPROVED, $approve->fresh()->status);
        $this->assertSame($reviewer->id, $approve->fresh()->reviewed_by);
        $this->assertNotNull($approve->fresh()->reviewed_at);
        $this->assertSame(SupplierCategoryMapping::STATUS_REJECTED, $reject->fresh()->status);
        $this->assertSame($reviewer->id, $reject->fresh()->reviewed_by);
        $this->assertNotNull($reject->fresh()->reviewed_at);
        $this->assertSame('Wrong candidate', $reject->fresh()->notes);
        $this->assertSame(SupplierCategoryMapping::STATUS_IGNORED, $ignore->fresh()->status);
        $this->assertSame($reviewer->id, $ignore->fresh()->reviewed_by);
        $this->assertNotNull($ignore->fresh()->reviewed_at);
        $this->assertSame('Irrelevant candidate', $ignore->fresh()->notes);
        $this->assertSame(SupplierCategoryMapping::STATUS_PENDING_REVIEW, $reset->fresh()->status);
        $this->assertNull($reset->fresh()->reviewed_at);
        $this->assertNull($reset->fresh()->reviewed_by);

        $this->assertSame($counts, $this->protectedCounts());
        $this->assertEquals($snapshots['product'], $product->fresh()->only(array_keys($snapshots['product'])));
        $this->assertEquals($snapshots['supplierProduct'], $supplierProduct->fresh()->only(array_keys($snapshots['supplierProduct'])));
        $this->assertEquals($snapshots['category'], $category->fresh()->only(array_keys($snapshots['category'])));
        $this->assertEquals($snapshots['assignment'], $assignment->fresh()->only(array_keys($snapshots['assignment'])));
        $this->assertEquals($snapshots['attribute'], $attribute->fresh()->only(array_keys($snapshots['attribute'])));
        $this->assertEquals($snapshots['attributeValue'], $attributeValue->fresh()->only(array_keys($snapshots['attributeValue'])));
        $this->assertEquals($snapshots['productValue'], $productValue->fresh()->only(array_keys($snapshots['productValue'])));
    }

    public function test_manual_status_save_is_read_only_and_does_not_create_review_metadata_or_catalog_mutation(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $category = Category::factory()->create();
        $product = Product::factory()->supplierPublished()->create(['category_id' => $category->id]);
        $mapping = $this->mapping([
            'supplier_category_name' => 'Manual Status Save',
            'canonical_product_family_id' => $this->family('peripherals')->id,
        ]);

        $counts = $this->protectedCounts();
        $productSnapshot = $product->fresh()->only(['category_id', 'workflow_status', 'product_status', 'active', 'updated_at']);

        Livewire::test(EditSupplierCategoryMapping::class, ['record' => $mapping->getKey()])
            ->fillForm([
                'status' => SupplierCategoryMapping::STATUS_APPROVED,
                'notes' => 'Safe form save note',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $mapping->refresh();

        $this->assertSame(SupplierCategoryMapping::STATUS_PENDING_REVIEW, $mapping->status);
        $this->assertNull($mapping->reviewed_at);
        $this->assertNull($mapping->reviewed_by);
        $this->assertSame('Safe form save note', $mapping->notes);
        $this->assertSame($counts, $this->protectedCounts());
        $this->assertEquals($productSnapshot, $product->fresh()->only(array_keys($productSnapshot)));
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

    public function test_review_table_sorts_by_staged_product_count_ascending_and_descending_without_mutation(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $supplier = $this->supplier();
        $family = $this->family('peripherals');

        $oneProduct = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'One Product',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        ]);
        $threeProducts = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Three Products',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        ]);
        $fiveProducts = $this->mapping([
            'supplier_id' => $supplier->id,
            'supplier_category_name' => 'Five Products',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        ]);

        $this->supplierProducts($supplier, 'One Product', 1);
        $this->supplierProducts($supplier, 'Three Products', 3);
        $this->supplierProducts($supplier, 'Five Products', 5);

        $counts = $this->protectedCounts();
        $statuses = $this->mappingStatuses([$oneProduct, $threeProducts, $fiveProducts]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->sortTable('staged_product_count', 'asc')
            ->assertCanSeeTableRecords([$oneProduct, $threeProducts, $fiveProducts], inOrder: true)
            ->sortTable('staged_product_count', 'desc')
            ->assertCanSeeTableRecords([$fiveProducts, $threeProducts, $oneProduct], inOrder: true);

        $this->assertSame($statuses, $this->mappingStatuses([$oneProduct, $threeProducts, $fiveProducts]));
        $this->assertSame($counts, $this->protectedCounts());
    }

    public function test_review_table_sorts_by_confidence_custom_order_without_mutation(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $family = $this->family('peripherals');

        $high = $this->mapping([
            'supplier_category_name' => 'High Confidence',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_HIGH,
        ]);
        $medium = $this->mapping([
            'supplier_category_name' => 'Medium Confidence',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_MEDIUM,
        ]);
        $low = $this->mapping([
            'supplier_category_name' => 'Low Confidence',
            'canonical_product_family_id' => $family->id,
            'confidence' => SupplierCategoryMapping::CONFIDENCE_LOW,
        ]);
        $unknown = $this->mapping([
            'supplier_category_name' => 'Unknown Confidence',
            'canonical_product_family_id' => $family->id,
            'confidence' => null,
        ]);

        $counts = $this->protectedCounts();
        $statuses = $this->mappingStatuses([$high, $medium, $low, $unknown]);

        Livewire::test(ListSupplierCategoryMappings::class)
            ->sortTable('confidence', 'asc')
            ->assertCanSeeTableRecords([$low, $medium, $high, $unknown], inOrder: true)
            ->sortTable('confidence', 'desc')
            ->assertCanSeeTableRecords([$high, $medium, $low, $unknown], inOrder: true);

        $this->assertSame($statuses, $this->mappingStatuses([$high, $medium, $low, $unknown]));
        $this->assertSame($counts, $this->protectedCounts());
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

    private function supplierProducts(Supplier $supplier, string $categoryName, int $count): void
    {
        foreach (range(1, $count) as $_) {
            $this->supplierProduct($supplier, $categoryName);
        }
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

    /**
     * @param  array<int, SupplierCategoryMapping>  $mappings
     * @return array<int, string|null>
     */
    private function mappingStatuses(array $mappings): array
    {
        return collect($mappings)
            ->mapWithKeys(fn (SupplierCategoryMapping $mapping): array => [$mapping->id => $mapping->fresh()->status])
            ->all();
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
