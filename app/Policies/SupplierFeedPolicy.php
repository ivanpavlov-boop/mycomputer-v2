<?php

namespace App\Policies;

class SupplierFeedPolicy extends PermissionPolicy
{
    protected string $permission = 'manage feeds';
}
