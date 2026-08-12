<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use LogicException;
use Throwable;

final class ImportHistoryReferenceProtectedException extends LogicException
{
    public static function supplierDeletion(?Throwable $previous = null): self
    {
        return new self('A supplier referenced by import history cannot be deleted.', 0, $previous);
    }

    public static function importJobDeletion(?Throwable $previous = null): self
    {
        return new self('An import job referenced by import history cannot be deleted.', 0, $previous);
    }

    public static function importJobIdentity(): self
    {
        return new self('Import job generation identity cannot change after import history exists.');
    }

    public static function supplierFeedDeletion(?Throwable $previous = null): self
    {
        return new self('A supplier feed referenced by import history cannot be deleted.', 0, $previous);
    }

    public static function supplierFeedIdentity(): self
    {
        return new self('Supplier feed generation identity cannot change after import history exists.');
    }

    /** @param array<int, string> $constraintNames */
    public static function matchesHistoricalForeignKeyRestriction(
        QueryException $exception,
        string $deletedTable,
        array $constraintNames,
        bool $historyReferenceExists,
    ): bool {
        if (preg_match('/^delete from\s+[`"\[]?'.preg_quote($deletedTable, '/').'[`"\]]?(?:\s|$)/i', trim($exception->getSql())) !== 1) {
            return false;
        }

        $message = strtolower($exception->getMessage());
        foreach ($constraintNames as $constraintName) {
            if (str_contains($message, strtolower($constraintName))) {
                return true;
            }
        }

        $sqlState = strtoupper((string) ($exception->errorInfo[0] ?? ''));
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $historyReferenceExists
            && $sqlState === '23000'
            && $driverCode === 19
            && str_contains($message, 'foreign key constraint failed');
    }
}
