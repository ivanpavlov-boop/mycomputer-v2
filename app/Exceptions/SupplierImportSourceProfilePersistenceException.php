<?php

namespace App\Exceptions;

use RuntimeException;

final class SupplierImportSourceProfilePersistenceException extends RuntimeException
{
    public const CODE = 'source_profile_persistence_failed';

    public const OPERATION = 'INSERT_SUPPLIER_IMPORT_SOURCE_PROFILE';

    public function __construct(
        public readonly ?string $sqlstate,
        public readonly ?int $driverCode,
    ) {
        parent::__construct(self::CODE);
    }

    /** @return array{code: string, sqlstate: ?string, driver_code: ?int, operation: string} */
    public function metadata(): array
    {
        return [
            'code' => self::CODE,
            'sqlstate' => $this->sqlstate,
            'driver_code' => $this->driverCode,
            'operation' => self::OPERATION,
        ];
    }

    public static function fromErrorInfo(mixed $errorInfo): self
    {
        return new self(
            is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0])
                ? $errorInfo[0]
                : null,
            is_array($errorInfo) && isset($errorInfo[1]) && is_int($errorInfo[1])
                ? $errorInfo[1]
                : null,
        );
    }
}
