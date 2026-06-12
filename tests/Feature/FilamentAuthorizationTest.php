<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\CsvExportJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_permission_enforcement_on_major_resources(): void
    {
        $manager = User::factory()->create();
        $support = User::factory()->create();
        $manager->assignRole('manager');
        $support->assignRole('support');

        $this->actingAs($manager);
        $this->assertTrue(ProductResource::canViewAny());
        $this->assertTrue(CategoryResource::canViewAny());
        $this->assertTrue(OrderResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(RoleResource::canViewAny());

        $this->actingAs($support);
        $this->assertFalse(ProductResource::canViewAny());
        $this->assertTrue(OrderResource::canViewAny());
    }

    public function test_policies_use_permissions(): void
    {
        $manager = User::factory()->create();
        $support = User::factory()->create();
        $manager->assignRole('manager');
        $support->assignRole('support');

        $product = new Product;
        $order = new Order;

        $this->assertTrue($manager->can('update', $product));
        $this->assertFalse($support->can('update', $product));
        $this->assertTrue($support->can('view', $order));
        $this->assertTrue($support->can('update', $order));
    }

    public function test_admin_safety_rules(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $otherAdmin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $otherAdmin->assignRole('admin');

        $this->actingAs($admin);
        $this->assertFalse(UserResource::canDelete($admin));
        $this->assertFalse(UserResource::canDeactivate($admin));
        $this->assertTrue(UserResource::canDelete($otherAdmin));

        $otherAdmin->removeRole('admin');

        $this->assertFalse(UserResource::canDelete($admin));
        $this->assertFalse(UserResource::canDeactivate($admin));
    }

    public function test_default_roles_cannot_be_deleted_from_filament(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->assertFalse(RoleResource::canDelete(Role::findByName('admin')));
        $this->assertFalse(RoleResource::canDelete(Role::findByName('manager')));
    }

    public function test_csv_downloads_require_manage_imports_permission_and_signed_url(): void
    {
        File::ensureDirectoryExists(storage_path('app/exports'));
        File::put(storage_path('app/exports/hardening.csv'), "sku,price\nTEST,10\n");

        $job = CsvExportJob::query()->create([
            'type' => 'products',
            'status' => 'completed',
            'file_path' => 'exports/hardening.csv',
        ]);

        $user = User::factory()->create();
        $signedUrl = URL::signedRoute('csv.exports.download', $job);

        $this->actingAs($user)->get($signedUrl)->assertForbidden();

        Permission::findOrCreate('manage imports', 'web');
        $user->givePermissionTo('manage imports');

        $this->actingAs($user)->get(route('csv.exports.download', $job))->assertForbidden();
        $this->actingAs($user)->get($signedUrl)->assertOk();
    }
}
