<?php

namespace App\Policies;

class MarketingPolicy extends PermissionPolicy
{
    protected string $permission = 'manage marketing';
}
