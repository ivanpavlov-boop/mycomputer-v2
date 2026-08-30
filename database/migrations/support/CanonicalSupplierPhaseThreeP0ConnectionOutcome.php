<?php

namespace Database\Migrations\Support;

use PDOException;

final class CanonicalSupplierPhaseThreeP0ConnectionOutcome
{
    public static function isUncertain(PDOException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return is_string($sqlState) && str_starts_with($sqlState, '08');
    }
}
