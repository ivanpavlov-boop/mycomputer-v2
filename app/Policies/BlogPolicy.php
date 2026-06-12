<?php

namespace App\Policies;

class BlogPolicy extends PermissionPolicy
{
    protected string $permission = 'manage blog';
}
