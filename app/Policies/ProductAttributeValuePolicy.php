<?php

namespace App\Policies;

class ProductAttributeValuePolicy extends PermissionPolicy
{
    protected string $permission = 'manage products';
}
