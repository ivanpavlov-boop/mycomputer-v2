<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

class ActiveUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?UserContract
    {
        return $this->activeUser(parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?UserContract
    {
        return $this->activeUser(parent::retrieveByToken($identifier, $token));
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?UserContract
    {
        return $this->activeUser(parent::retrieveByCredentials($credentials));
    }

    private function activeUser(?UserContract $user): ?UserContract
    {
        if (! $user instanceof User) {
            return $user;
        }

        return $user->isActiveAdminAccount() ? $user : null;
    }
}
