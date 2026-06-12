<?php

namespace App\Policies;

use App\Models\User;

abstract class PermissionPolicy
{
    protected string $permission;

    public function viewAny(User $user): bool
    {
        return $user->can($this->permission);
    }

    public function view(User $user): bool
    {
        return $user->can($this->permission);
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission);
    }

    public function update(User $user): bool
    {
        return $user->can($this->permission);
    }

    public function delete(User $user): bool
    {
        return $user->can($this->permission);
    }

    public function restore(User $user): bool
    {
        return $user->can($this->permission);
    }

    public function forceDelete(User $user): bool
    {
        return $user->can($this->permission);
    }
}
