<?php

namespace App\Policies;

use App\Models\User;

class RolePolicy extends PermissionPolicy
{
    protected string $permission = 'manage roles';

    public function viewAny(User $user): bool
    {
        return $user->canManageRoles();
    }

    public function view(User $user): bool
    {
        return $user->canManageRoles();
    }

    public function create(User $user): bool
    {
        return $user->canManageRoles();
    }

    public function update(User $user): bool
    {
        return $user->canManageRoles();
    }

    public function delete(User $user): bool
    {
        return $user->canManageRoles();
    }
}
