<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\CatalogSyncBatch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_legacy_admin_users_keep_safe_super_admin_access(): void
    {
        $legacyAdmin = User::factory()->create(['role' => null, 'is_active' => true]);
        $legacyAdmin->assignRole('admin');

        $this->assertSame(User::ROLE_SUPER_ADMIN, $legacyAdmin->primaryRole());
        $this->assertTrue($legacyAdmin->isSuperAdmin());
        $this->assertTrue($legacyAdmin->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue($legacyAdmin->canManageUsers());
        $this->assertTrue($legacyAdmin->canRunCreateSync());
        $this->assertTrue($legacyAdmin->canRunUpdateSync());
    }

    public function test_super_admin_can_view_user_management_and_change_role(): void
    {
        $superAdmin = $this->superAdmin();
        $target = User::factory()->create([
            'role' => User::ROLE_PRODUCT_DATA_ENTRY,
            'first_name' => 'Product',
            'last_name' => 'Editor',
            'name' => 'Product Editor',
            'email' => 'editor@example.test',
            'is_active' => true,
        ]);
        $target->assignRole(User::ROLE_PRODUCT_DATA_ENTRY);

        $this->actingAs($superAdmin);

        $this->assertTrue(UserResource::canViewAny());
        $this->get(UserResource::getUrl('index'))->assertOk();

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm($this->userFormData($target, [
                'role' => User::ROLE_PRICING_MANAGER,
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertSame(User::ROLE_PRICING_MANAGER, $target->role);
        $this->assertTrue($target->hasRole(User::ROLE_PRICING_MANAGER));
    }

    public function test_non_super_admin_cannot_access_user_management_or_change_roles(): void
    {
        $catalogManager = User::factory()->create(['role' => User::ROLE_CATALOG_MANAGER]);
        $catalogManager->assignRole(User::ROLE_CATALOG_MANAGER);
        $target = User::factory()->create(['role' => User::ROLE_PRODUCT_EDITOR]);

        $this->actingAs($catalogManager);

        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(UserResource::canEdit($target));
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => $target]))->assertForbidden();
    }

    public function test_last_active_super_admin_cannot_be_downgraded_or_deactivated(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin);

        Livewire::test(EditUser::class, ['record' => $superAdmin->getKey()])
            ->fillForm($this->userFormData($superAdmin, [
                'role' => User::ROLE_CATALOG_MANAGER,
            ]))
            ->call('save')
            ->assertHasErrors(['role']);

        Livewire::test(EditUser::class, ['record' => $superAdmin->getKey()])
            ->fillForm($this->userFormData($superAdmin, [
                'is_active' => false,
            ]))
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertFalse(UserResource::canDelete($superAdmin));
        $this->assertFalse(UserResource::canDeactivate($superAdmin));
        $this->assertSame(User::ROLE_SUPER_ADMIN, $superAdmin->fresh()->role);
        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_catalog_sync_permissions_allow_preview_but_block_writes_for_viewer_auditor(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER_AUDITOR]);
        $viewer->assignRole(User::ROLE_VIEWER_AUDITOR);
        $supplier = Supplier::factory()->create();
        $supplierProduct = $this->supplierProduct($supplier);

        config()->set('catalog_sync.create_enabled', true);
        config()->set('catalog_sync.update_enabled', true);

        $this->actingAs($viewer);

        $this->assertTrue(CatalogSyncPreview::canAccess());
        $this->get(CatalogSyncPreview::getUrl())->assertOk();

        $beforeProductCount = Product::query()->count();
        $beforeSupplierProduct = $supplierProduct->fresh()->only(['product_id', 'status', 'synced_at', 'mapping_notes']);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedCreateProducts')
            ->assertSet('lastManualSyncResult.created', 0)
            ->assertSet('lastManualSyncResult.skipped', 1);

        Livewire::test(CatalogSyncPreview::class)
            ->set('selectedUpdateSupplierProductIds', [$supplierProduct->id])
            ->call('syncSelectedUpdateProducts')
            ->assertSet('lastManualUpdateResult.updated', 0)
            ->assertSet('lastManualUpdateResult.skipped', 1);

        $this->assertSame($beforeProductCount, Product::query()->count());
        $this->assertSame($beforeSupplierProduct, $supplierProduct->fresh()->only(['product_id', 'status', 'synced_at', 'mapping_notes']));
        $this->assertSame(0, CatalogSyncBatch::query()->count());
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userFormData(User $user, array $overrides = []): array
    {
        return array_merge([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'company_name' => $user->company_name,
            'vat_number' => $user->vat_number,
            'is_active' => $user->is_active,
            'role' => $user->role,
            'password' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'ROLE-SAFE-001',
            'ean' => '1234567890123',
            'mpn' => 'ROLE-SAFE-MPN',
            'name' => 'Role Safe Supplier Product',
            'brand_name' => 'Role Brand',
            'category_name' => 'Role Category',
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
