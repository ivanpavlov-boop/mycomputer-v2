<?php

namespace App\Policies;

class AiPolicy extends PermissionPolicy
{
    protected string $permission = 'manage settings';
}
