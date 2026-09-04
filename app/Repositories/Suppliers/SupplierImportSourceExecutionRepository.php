<?php

namespace App\Repositories\Suppliers;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Models\SupplierImportSourceExecution;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class SupplierImportSourceExecutionRepository
{
    public function findByHistoryForUpdate(Connection $connection, int $importHistoryId): ?SupplierImportSourceExecution
    {
        $row = $connection->table('supplier_import_source_executions')
            ->where('import_history_id', $importHistoryId)
            ->lockForUpdate()
            ->first();

        return $row === null ? null : $this->modelFromRow($row, $connection->getName());
    }

    public function resolveOrInsertWithinTransaction(
        Connection $connection,
        CanonicalSupplierImportSourceExecution $execution,
    ): SupplierImportSourceExecution {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('source_execution_transaction_required');
        }

        $attributes = $execution->persistenceAttributes();
        $existing = $this->findConflictingRow($connection, $attributes);

        if ($existing !== null) {
            $this->assertByteIdentical($existing, $attributes);

            return $this->modelFromRow($existing, $connection->getName());
        }

        try {
            $id = $connection->table('supplier_import_source_executions')->insertGetId($attributes);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000'
                || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw new RuntimeException('source_execution_persistence_failed');
            }

            $existing = $this->findConflictingRow($connection, $attributes);
            if ($existing === null) {
                throw new RuntimeException('source_execution_persistence_failed');
            }

            $this->assertByteIdentical($existing, $attributes);

            return $this->modelFromRow($existing, $connection->getName());
        }

        $created = $connection->table('supplier_import_source_executions')->find($id);
        if ($created === null) {
            throw new RuntimeException('source_execution_persistence_failed');
        }

        $this->assertByteIdentical($created, $attributes);

        return $this->modelFromRow($created, $connection->getName());
    }

    /** @param array<string, int|string> $expected */
    public function assertByteIdentical(object $existing, array $expected): void
    {
        foreach ($expected as $field => $value) {
            $actual = $existing instanceof SupplierImportSourceExecution
                ? $existing->getRawOriginal($field)
                : ($existing->{$field} ?? null);

            if ($actual !== $value) {
                throw new RuntimeException('source_execution_fingerprint_collision');
            }
        }
    }

    /** @param array<string, int|string> $attributes */
    private function findConflictingRow(Connection $connection, array $attributes): ?object
    {
        $rows = $connection->table('supplier_import_source_executions')
            ->where(function ($query) use ($attributes): void {
                $query->where('source_execution_fingerprint', $attributes['source_execution_fingerprint'])
                    ->orWhere('import_history_id', $attributes['import_history_id']);
            })
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($rows->count() > 1) {
            throw new RuntimeException('source_execution_fingerprint_collision');
        }

        return $rows->first();
    }

    private function modelFromRow(object $row, string $connection): SupplierImportSourceExecution
    {
        return (new SupplierImportSourceExecution)
            ->setConnection($connection)
            ->newFromBuilder((array) $row);
    }
}
