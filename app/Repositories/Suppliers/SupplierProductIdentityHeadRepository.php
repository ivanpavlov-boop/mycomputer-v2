<?php

namespace App\Repositories\Suppliers;

use App\Data\Suppliers\Imports\SupplierProductLogicalKey;
use App\Models\SupplierProductIdentityHead;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class SupplierProductIdentityHeadRepository
{
    private function insertOrLockInTransaction(Connection $connection, SupplierProductLogicalKey $key): SupplierProductIdentityHead
    {
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() < 1
            || ! $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('supplier_product_identity_head_mysql_transaction_required');
        }

        $attributes = $key->persistenceAttributes();
        try {
            $connection->table('supplier_product_identity_heads')->insert($attributes);
        } catch (QueryException $exception) {
            if (! $this->isEligibleDuplicate($exception)) {
                throw $exception;
            }
        }

        $row = $connection->table('supplier_product_identity_heads')->where($attributes)->lockForUpdate()->first();
        if ($row === null || ! is_int($row->id ?? null) || $row->id < 1
            || ! is_string($row->created_at ?? null)) {
            throw new RuntimeException('supplier_product_logical_identity_conflict');
        }
        foreach ($attributes as $field => $value) {
            if (($row->{$field} ?? null) !== $value) {
                throw new RuntimeException('supplier_product_logical_identity_conflict');
            }
        }

        return (new SupplierProductIdentityHead)->setConnection($connection->getName())->newFromBuilder((array) $row);
    }

    private function isEligibleDuplicate(QueryException $exception): bool
    {
        $error = $exception->errorInfo;
        if (($error[0] ?? null) !== '23000' || ($error[1] ?? null) !== 1062
            || ! is_string($error[2] ?? null) || ! str_starts_with($error[2], "Duplicate entry '")) {
            return false;
        }

        return str_ends_with($error[2], "' for key 'uq_supplier_product_identity_head'")
            || str_ends_with($error[2], "' for key 'supplier_product_identity_heads.uq_supplier_product_identity_head'");
    }
}
