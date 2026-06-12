<?php

namespace App\Policies;

class UserPolicy extends PermissionPolicy
{
    protected string $permission = 'manage users';
}
