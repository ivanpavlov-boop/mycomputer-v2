<?php

namespace App\Policies;

class PagePolicy extends PermissionPolicy
{
    protected string $permission = 'manage pages';
}
