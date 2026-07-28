<?php

namespace App\Policies;

use App\Models\LeasingApplication;
use App\Models\User;

class LeasingApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, LeasingApplication $application): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LeasingApplication $application): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, LeasingApplication $application): bool
    {
        return false;
    }

    public function restore(User $user, LeasingApplication $application): bool
    {
        return false;
    }

    public function forceDelete(User $user, LeasingApplication $application): bool
    {
        return false;
    }

    public function changeStatus(User $user, LeasingApplication $application): bool
    {
        return $this->canManage($user);
    }

    public function assign(User $user, LeasingApplication $application): bool
    {
        return $this->canManage($user);
    }

    public function addNote(User $user, LeasingApplication $application): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        return $user->isActiveAdminAccount()
            && ($user->isSuperAdmin() || $user->can('view orders') || $user->can('manage orders'));
    }

    private function canManage(User $user): bool
    {
        return $user->isActiveAdminAccount()
            && ($user->isSuperAdmin() || $user->can('manage orders'));
    }
}
