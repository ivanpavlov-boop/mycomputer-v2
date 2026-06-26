<?php

namespace App\Policies;

use App\Models\User;

abstract class PermissionPolicy
{
    protected string $permission;

    public function viewAny(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function view(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function update(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function delete(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function restore(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function forceDelete(User $user): bool
    {
        return $this->canAccess($user);
    }

    protected function canAccess(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can($this->permission);
    }
}
