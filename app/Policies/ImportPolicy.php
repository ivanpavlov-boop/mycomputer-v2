<?php

namespace App\Policies;

use App\Models\ImportJob;
use App\Models\User;

class ImportPolicy extends PermissionPolicy
{
    protected string $permission = 'manage imports';

    public function delete(User $user, ?ImportJob $job = null): bool
    {
        return $this->canAccess($user)
            && $job instanceof ImportJob
            && ! $job->hasImportHistoryReferences();
    }

    public function deleteAny(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function forceDelete(User $user, ?ImportJob $job = null): bool
    {
        return $this->delete($user, $job);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->canAccess($user);
    }
}
