<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy extends PermissionPolicy
{
    protected string $permission = 'manage suppliers';

    public function delete(User $user, ?Supplier $supplier = null): bool
    {
        return $this->canAccess($user)
            && $supplier instanceof Supplier
            && ! $supplier->hasImportHistoryReferences();
    }

    public function deleteAny(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function forceDelete(User $user, ?Supplier $supplier = null): bool
    {
        return $this->delete($user, $supplier);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->canAccess($user);
    }
}
