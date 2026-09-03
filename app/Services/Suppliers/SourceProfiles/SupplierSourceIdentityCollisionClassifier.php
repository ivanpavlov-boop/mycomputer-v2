<?php

namespace App\Services\Suppliers\SourceProfiles;

use Illuminate\Database\QueryException;

final class SupplierSourceIdentityCollisionClassifier
{
    private const PREFIX = "Duplicate entry '";

    private const SUFFIX = "' for key 'supplier_import_source_profiles.uq_import_source_profile_identity'";

    public function isEligible(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;

        if (! is_array($errorInfo)
            || ! array_key_exists(0, $errorInfo)
            || ! array_key_exists(1, $errorInfo)
            || ! array_key_exists(2, $errorInfo)
            || ! is_string($errorInfo[0])
            || $errorInfo[0] !== '23000'
            || ! is_int($errorInfo[1])
            || $errorInfo[1] !== 1062
            || ! is_string($errorInfo[2])) {
            return false;
        }

        $diagnostic = $errorInfo[2];
        if (! str_starts_with($diagnostic, self::PREFIX)
            || ! str_ends_with($diagnostic, self::SUFFIX)) {
            return false;
        }

        return strlen($diagnostic) > strlen(self::PREFIX) + strlen(self::SUFFIX);
    }
}
