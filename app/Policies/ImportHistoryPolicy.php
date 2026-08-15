<?php

namespace App\Policies;

use App\Models\ImportHistory;
use App\Models\User;

class ImportHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ImportHistory $history): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ImportHistory $history): bool
    {
        return false;
    }

    public function delete(User $user, ImportHistory $history): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ImportHistory $history): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ImportHistory $history): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function canView(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can('manage imports');
    }
}
