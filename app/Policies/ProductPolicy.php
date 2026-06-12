<?php

namespace App\Policies;

class ProductPolicy extends PermissionPolicy
{
    protected string $permission = 'manage products';
}
