<?php

namespace App\Policies;

class CustomerPolicy extends PermissionPolicy
{
    protected string $permission = 'manage customers';
}
