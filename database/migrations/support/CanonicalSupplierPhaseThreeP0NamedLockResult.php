<?php

namespace Database\Migrations\Support;

final class CanonicalSupplierPhaseThreeP0NamedLockResult
{
    public const ACQUIRED = 'ACQUIRED';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public const RELEASED = 'RELEASED';

    public const NOT_OWNED = 'NOT_OWNED';

    public static function acquisition(mixed $value): string
    {
        return $value === 1 ? self::ACQUIRED : self::UNAVAILABLE;
    }

    public static function release(mixed $value): string
    {
        return match (true) {
            $value === 1 => self::RELEASED,
            $value === 0 => self::NOT_OWNED,
            default => self::UNAVAILABLE,
        };
    }
}
