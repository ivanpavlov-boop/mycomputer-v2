<?php

namespace App\Policies;

use App\Models\User;

class OrderPolicy extends PermissionPolicy
{
    protected string $permission = 'manage orders';

    public function viewAny(User $user): bool
    {
        return $user->can('view orders') || $user->can('manage orders');
    }

    public function view(User $user): bool
    {
        return $user->can('view orders') || $user->can('manage orders');
    }
}
