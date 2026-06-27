<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

trait RequiresFilamentPermission
{
    public static function canViewAny(): bool
    {
        return static::canAccessResource();
    }

    public static function canCreate(): bool
    {
        return static::canAccessResource();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canDeleteAny(): bool
    {
        return static::canAccessResource();
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canForceDeleteAny(): bool
    {
        return static::canAccessResource();
    }

    public static function canRestore(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canRestoreAny(): bool
    {
        return static::canAccessResource();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccessResource();
    }

    protected static function canAccessResource(): bool
    {
        if (auth()->user()?->isSuperAdmin()) {
            return true;
        }

        $permission = static::$permission ?? null;

        if (! $permission) {
            return true;
        }

        return (bool) auth()->user()?->can($permission);
    }
}
