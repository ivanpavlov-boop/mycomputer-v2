<?php

namespace App\Policies;

class CategoryPolicy extends PermissionPolicy
{
    protected string $permission = 'manage categories';
}
