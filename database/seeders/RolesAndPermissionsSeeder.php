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
            ?->assignRole('admin');
    }
}
