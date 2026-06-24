<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy extends PermissionPolicy
{
    protected string $permission = 'manage users';

    public function viewAny(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function view(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function create(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function update(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function delete(User $user): bool
    {
        return $user->canManageUsers();
    }
}
