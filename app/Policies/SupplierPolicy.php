<?php

namespace App\Policies;

class SupplierPolicy extends PermissionPolicy
{
    protected string $permission = 'manage suppliers';
}
