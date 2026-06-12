<?php

namespace App\Policies;

class ImportPolicy extends PermissionPolicy
{
    protected string $permission = 'manage imports';
}
