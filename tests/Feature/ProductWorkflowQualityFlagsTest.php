<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Filament\Resources\ProductQualityFlags\ProductQualityFlagResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductWorkflowQualityFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manually_created_product_defaults_to_draft_and_hidden(): void
    {
        $this->actingAsRole(User::ROLE_PRODUCT_EDITOR);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Manual Workflow Product',
                'slug' => 'manual-workflow-product',
                'sku' => 'MANUAL-WORKFLOW-001',
                'source' => Product::SOURCE_MANUAL,
                'price' => 99,
                'price_source' => Product::PRICE_SOURCE_MANUAL,
                'quantity' => 5,
                'reserved_quantity' => 0,
                'product_status' => 'active',
                'stock_status' => 'in_stock',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('sku', 'MANUAL-WORKFLOW-001')->firstOrFail();

        $this->assertSame(Product::SOURCE_MANUAL, $product->source);
        $this->assertSame(Product::WORKFLOW_DRAFT, $product->workflow_status);
        $this->assertSame('draft', $product->product_status);
        $this->assertFalse((bool) $product->active);
        $this->assertNull($product->published_at);
    }

    public function test_product_workflow_transitions_require_catalog_manager_or_super_admin_for_approval_and_publish(): void
    {
        $editor = $this->actingAsRole(User::ROLE_PRODUCT_EDITOR);
        $manager = $this->userWithRole(User::ROLE_CATALOG_MANAGER);

        $product = Product::factory()->manualDraft()->create();

        $product->transitionWorkflowTo(Product::WORKFLOW_PENDING_REVIEW, $editor);
        $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $product->fresh()->workflow_status);
        $this->assertSame($editor->id, $product->fresh()->submitted_by);

        $product->transitionWorkflowTo(Product::WORKFLOW_APPROVED, $editor);
        $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $product->fresh()->workflow_status);

        $product->transitionWorkflowTo(Product::WORKFLOW_APPROVED, $manager);
        $this->assertSame(Product::WORKFLOW_APPROVED, $product->fresh()->workflow_status);
        $this->assertFalse((bool) $product->fresh()->active);

        $product->transitionWorkflowTo(Product::WORKFLOW_PUBLISHED, $manager);
        $product->refresh();

        $this->assertSame(Product::WORKFLOW_PUBLISHED, $product->workflow_status);
        $this->assertTrue((bool) $product->active);
        $this->assertSame('active', $product->product_status);
        $this->assertNotNull($product->published_at);
        $this->assertSame($manager->id, $product->published_by);
    }

    public function test_changes_can_be_requested_with_review_notes(): void
    {
        $manager = $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $product = Product::factory()->manualDraft()->create([
            'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
            'submitted_at' => now(),
        ]);

        $product->transitionWorkflowTo(Product::WORKFLOW_CHANGES_REQUESTED, $manager, 'Please add Bulgarian SEO description.');
        $product->refresh();

        $this->assertSame(Product::WORKFLOW_CHANGES_REQUESTED, $product->workflow_status);
        $this->assertSame('Please add Bulgarian SEO description.', $product->review_notes);
        $this->assertSame($manager->id, $product->returned_by);
        $this->assertFalse((bool) $product->active);
    }

    public function test_quality_flags_are_configurable_and_non_blocking(): void
    {
        $flag = ProductQualityFlag::query()->create([
            'code' => 'missing_en_seo',
            'label_bg' => 'Липсва EN SEO',
            'label_en' => 'Missing EN SEO',
            'severity' => ProductQualityFlag::SEVERITY_MEDIUM,
            'responsible_role' => User::ROLE_SEO_MARKETING,
            'type' => ProductQualityFlag::TYPE_SEO,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $product = Product::factory()->create(['workflow_status' => Product::WORKFLOW_PUBLISHED]);

        ProductQualityFlagAssignment::query()->create([
            'product_id' => $product->id,
            'product_quality_flag_id' => $flag->id,
            'status' => ProductQualityFlagAssignment::STATUS_ACTIVE,
            'note' => 'English SEO can be completed later.',
        ]);

        $this->assertSame(1, $product->activeQualityFlagAssignments()->count());
        $this->assertTrue(Product::published()->whereKey($product)->exists());
    }

    public function test_product_quality_flag_admin_is_limited_to_catalog_managers(): void
    {
        $catalogManager = $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $this->assertTrue(ProductQualityFlagResource::canViewAny());
        $this->get(ProductQualityFlagResource::getUrl())->assertOk();

        $viewer = $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);
        $this->assertFalse(ProductQualityFlagResource::canViewAny());
        $this->assertFalse($viewer->canManageProductQualityFlags());
        $this->assertTrue($catalogManager->canManageProductQualityFlags());
    }

    public function test_catalog_sync_create_defaults_supplier_product_to_published(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $this->createGlobalPricingRule();

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Supplier Published Product',
            'supplier_sku' => 'SUP-PUBLISHED-001',
            'ean' => null,
            'mpn' => null,
            'price' => 100,
            'quantity' => 5,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 1);

        $product = Product::query()->where('supplier_sku', 'SUP-PUBLISHED-001')->firstOrFail();

        $this->assertSame(Product::SOURCE_SUPPLIER_IMPORT, $product->source);
        $this->assertSame(Product::WORKFLOW_PUBLISHED, $product->workflow_status);
        $this->assertSame('active', $product->product_status);
        $this->assertTrue((bool) $product->active);
        $this->assertNotNull($product->published_at);
    }

    public function test_catalog_sync_update_preserves_workflow_status_and_content(): void
    {
        config(['catalog_sync.update_enabled' => true]);

        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $this->createGlobalPricingRule();

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM']);
        $product = Product::factory()->supplierPublished()->create([
            'supplier_id' => $supplier->id,
            'ean' => '1234567890123',
            'name' => 'Curated Bulgarian Name',
            'workflow_status' => Product::WORKFLOW_APPROVED,
            'active' => false,
            'published_at' => null,
            'price' => 100,
            'quantity' => 1,
        ]);
        $supplierProduct = $this->supplierProduct($supplier, [
            'name' => 'Supplier Name Must Not Win',
            'supplier_sku' => 'SUP-UPDATE-001',
            'ean' => '1234567890123',
            'price' => 120,
            'quantity' => 8,
        ]);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedUpdateProducts')
            ->assertSet('lastManualUpdateResult.updated', 1);

        $product->refresh();

        $this->assertSame(Product::WORKFLOW_APPROVED, $product->workflow_status);
        $this->assertFalse((bool) $product->active);
        $this->assertNull($product->published_at);
        $this->assertSame('Curated Bulgarian Name', $product->name);
        $this->assertSame('144.00', $product->price);
        $this->assertSame(8, $product->quantity);
    }

    public function test_product_admin_pages_still_render(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);

        $this->get(ProductResource::getUrl())->assertOk();
        $this->get(ProductResource::getUrl('create'))->assertOk();
    }

    private function actingAsRole(string $role): User
    {
        $user = $this->userWithRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function userWithRole(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['role' => $role]);
        $user->assignRole($role);

        return $user;
    }

    private function createGlobalPricingRule(): void
    {
        PricingRule::query()->create([
            'name' => 'Workflow global margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => fake()->unique()->bothify('SUP-####??'),
            'ean' => fake()->unique()->numerify('#############'),
            'mpn' => fake()->unique()->bothify('MPN-####??'),
            'name' => 'Workflow Supplier Product',
            'brand_name' => 'Workflow Brand',
            'category_name' => 'Workflow Category',
            'price' => 100,
            'quantity' => 5,
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => fake()->unique()->sha1(),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }
}
