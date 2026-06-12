<?php

namespace App\Policies;

class PcBuilderPolicy extends PermissionPolicy
{
    protected string $permission = 'manage products';
}
