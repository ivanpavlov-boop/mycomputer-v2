<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = [
        User::ROLE_SUPER_ADMIN,
        User::ROLE_CATALOG_MANAGER,
        User::ROLE_PRODUCT_EDITOR,
        User::ROLE_PRODUCT_DATA_ENTRY,
        User::ROLE_PRICING_MANAGER,
        User::ROLE_INVENTORY_MANAGER,
        User::ROLE_SEO_MARKETING,
        User::ROLE_ORDER_MANAGER,
        User::ROLE_VIEWER_AUDITOR,
        'admin',
        'manager',
        'support',
        'customer',
        'b2b_customer',
    ];

    public const PERMISSIONS = [
        'manage products',
        'manage availability statuses',
        'manage attribute mappings',
        'manage categories',
        'manage brands',
        'view orders',
        'manage orders',
        'refund orders',
        'view customers',
        'manage customers',
        'manage suppliers',
        'manage feeds',
        'manage supplier imports',
        'run supplier imports',
        'view supplier import logs',
        'force supplier imports',
        'manage imports',
        'manage blog',
        'manage pages',
        'manage content pages',
        'publish content pages',
        'manage templates',
        'manage reusable blocks',
        'manage settings',
        'manage marketing',
        'manage erp',
        'view erp logs',
        'retry erp sync',
        'manage b2b companies',
        'view b2b companies',
        'manage quotes',
        'view quotes',
        'convert quotes',
        'view service tickets',
        'manage service tickets',
        'manage users',
        'manage roles',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        Role::findByName('admin', 'web')->syncPermissions(
            Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_SUPER_ADMIN, 'web')->syncPermissions(
            Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_CATALOG_MANAGER, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'manage products',
                'manage availability statuses',
                'manage attribute mappings',
                'manage categories',
                'manage brands',
                'manage suppliers',
                'manage feeds',
                'manage supplier imports',
                'run supplier imports',
                'view supplier import logs',
                'manage imports',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_PRODUCT_EDITOR, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'manage products',
                'manage categories',
                'manage brands',
                'manage availability statuses',
                'manage attribute mappings',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_PRODUCT_DATA_ENTRY, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'manage products',
                'manage categories',
                'manage brands',
                'manage attribute mappings',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_PRICING_MANAGER, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'manage products',
                'manage marketing',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_INVENTORY_MANAGER, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'manage products',
                'manage availability statuses',
                'manage suppliers',
                'view supplier import logs',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_SEO_MARKETING, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'manage blog',
                'manage pages',
                'manage content pages',
                'publish content pages',
                'manage templates',
                'manage reusable blocks',
                'manage marketing',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_ORDER_MANAGER, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'view orders',
                'manage orders',
                'refund orders',
                'view customers',
                'manage customers',
                'view quotes',
                'manage quotes',
                'convert quotes',
                'view service tickets',
                'manage service tickets',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName(User::ROLE_VIEWER_AUDITOR, 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'view orders',
                'view customers',
                'view supplier import logs',
                'view erp logs',
                'view b2b companies',
                'view quotes',
                'view service tickets',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName('manager', 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'manage products',
                'manage availability statuses',
                'manage attribute mappings',
                'manage categories',
                'manage brands',
                'view orders',
                'manage orders',
                'view customers',
                'manage customers',
                'manage suppliers',
                'manage feeds',
                'manage supplier imports',
                'run supplier imports',
                'view supplier import logs',
                'manage imports',
                'manage blog',
                'manage pages',
                'manage content pages',
                'publish content pages',
                'manage templates',
                'manage reusable blocks',
                'manage marketing',
                'manage erp',
                'view erp logs',
                'retry erp sync',
                'manage b2b companies',
                'view b2b companies',
                'manage quotes',
                'view quotes',
                'convert quotes',
                'view service tickets',
                'manage service tickets',
            ])->where('guard_name', 'web')->get()
        );
        Role::findByName('support', 'web')->syncPermissions(
            Permission::query()->whereIn('name', [
                'view orders',
                'manage orders',
                'view customers',
                'view supplier import logs',
                'view b2b companies',
                'view quotes',
                'manage quotes',
                'view service tickets',
                'manage service tickets',
            ])->where('guard_name', 'web')->get()
        );

        User::query()
            ->where('email', 'admin@mycomputer.bg')
            ->first()
            ?->forceFill(['role' => User::ROLE_SUPER_ADMIN])
            ->save();

        User::query()
            ->where('email', 'admin@mycomputer.bg')
            ->first()
            ?->syncRoles([User::ROLE_SUPER_ADMIN, 'admin']);
    }
}
