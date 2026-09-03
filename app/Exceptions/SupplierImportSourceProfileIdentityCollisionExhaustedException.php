<?php

namespace App\Exceptions;

use RuntimeException;

final class SupplierImportSourceProfileIdentityCollisionExhaustedException extends RuntimeException
{
    public const CODE = 'source_profile_identity_collision_exhausted';

    public const OPERATION = 'INSERT_SUPPLIER_IMPORT_SOURCE_PROFILE';

    public const CONSTRAINT = 'uq_import_source_profile_identity';

    public function __construct()
    {
        parent::__construct(self::CODE);
    }

    /** @return array{code: string, operation: string, attempt: int, maximum: int, constraint: string} */
    public function metadata(): array
    {
        return [
            'code' => self::CODE,
            'operation' => self::OPERATION,
            'attempt' => 4,
            'maximum' => 4,
            'constraint' => self::CONSTRAINT,
        ];
    }
}
