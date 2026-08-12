<?php

namespace App\Policies;

use App\Models\SupplierFeed;
use App\Models\User;

class SupplierFeedPolicy extends PermissionPolicy
{
    protected string $permission = 'manage feeds';

    public function delete(User $user, ?SupplierFeed $feed = null): bool
    {
        return $this->canAccess($user)
            && $feed instanceof SupplierFeed
            && ! $feed->hasImportHistoryReferences();
    }

    public function deleteAny(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function forceDelete(User $user, ?SupplierFeed $feed = null): bool
    {
        return $this->delete($user, $feed);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->canAccess($user);
    }
}
