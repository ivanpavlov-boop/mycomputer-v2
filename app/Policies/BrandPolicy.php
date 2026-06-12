<?php

namespace App\Policies;

class BrandPolicy extends PermissionPolicy
{
    protected string $permission = 'manage brands';
}
