<?php

namespace App\Repositories\Suppliers;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\CanonicalSupplierImportSourcePayloadReceipt;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Models\SupplierImportSourceExecution;
use App\Models\SupplierImportSourcePayloadReceipt;
use App\Models\SupplierImportSourceProfile;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final readonly class SupplierImportSourcePayloadReceiptRepository
{
    public function resolveOrInsertWithinTransaction(
        Connection $connection,
        SupplierImportSourceExecution $execution,
        CanonicalSupplierImportSourcePayloadReceipt $receipt,
    ): SupplierImportSourcePayloadReceipt {
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() < 1) {
            throw new RuntimeException('payload_receipt_mysql_transaction_required');
        }

        if (! $execution->exists
            || $execution->isDirty()
            || $execution->getConnectionName() === null
            || $execution->getConnectionName() !== $connection->getName()
            || $execution->getConnection() !== $connection
            || $execution->getKey() !== $receipt->sourceExecutionId()
            || $execution->getRawOriginal('source_execution_fingerprint') !== $receipt->sourceExecutionFingerprint()) {
            throw new RuntimeException('payload_receipt_execution_binding_mismatch');
        }

        try {
            if (! $connection->getPdo()->inTransaction()) {
                throw new RuntimeException('payload_receipt_mysql_transaction_required');
            }

            $this->verifyLockedExecution($connection, $execution, $receipt);
            $existing = $this->findConflictingRow($connection, $receipt);
            if ($existing !== null) {
                return $this->verifiedModel($existing, $receipt, $connection);
            }

            try {
                $id = $connection->table('supplier_import_source_payload_receipts')
                    ->insertGetId($receipt->persistenceAttributes());
            } catch (QueryException $exception) {
                if (! $this->isEligibleDuplicate($exception)) {
                    throw new RuntimeException('payload_receipt_persistence_failed');
                }

                $existing = $this->findConflictingRow($connection, $receipt);
                if ($existing === null) {
                    throw new RuntimeException('payload_receipt_persistence_failed');
                }

                return $this->verifiedModel($existing, $receipt, $connection);
            }

            $created = $connection->table('supplier_import_source_payload_receipts')->find($id);
            if ($created === null) {
                throw new RuntimeException('payload_receipt_persistence_failed');
            }

            return $this->verifiedModel($created, $receipt, $connection);
        } catch (QueryException|PDOException) {
            throw new RuntimeException('payload_receipt_persistence_failed');
        } catch (InvalidArgumentException) {
            throw new RuntimeException('payload_receipt_canonical_evidence_invalid');
        }
    }

    private function verifyLockedExecution(
        Connection $connection,
        SupplierImportSourceExecution $supplied,
        CanonicalSupplierImportSourcePayloadReceipt $receipt,
    ): void {
        $row = $connection->table('supplier_import_source_executions')
            ->where('id', $receipt->sourceExecutionId())
            ->lockForUpdate()
            ->first();
        if ($row === null || $row->source_execution_fingerprint !== $receipt->sourceExecutionFingerprint()) {
            throw new RuntimeException('payload_receipt_execution_binding_mismatch');
        }

        // Immutable profile evidence needs no earlier selector lock or profile resolution.
        $profile = $connection->table('supplier_import_source_profiles')
            ->where('id', $row->supplier_import_source_profile_id)
            ->first();
        if ($profile === null) {
            throw new RuntimeException('payload_receipt_execution_binding_mismatch');
        }

        $context = ResolvedSupplierImportSourceContext::fromProfile(
            (new SupplierImportSourceProfile)->setConnection($connection->getName())->newFromBuilder((array) $profile),
        );
        $identity = ImportJobIdentity::fromCanonicalBytes(
            $row->import_job_identity_canonical_bytes,
            $row->import_job_identity_fingerprint,
        );
        $canonical = CanonicalSupplierImportSourceExecution::fromContracts(
            $identity,
            $context,
            $row->import_history_id,
            str_replace(' ', 'T', $row->captured_at).'Z',
        );
        $repository = new SupplierImportSourceExecutionRepository;
        $repository->assertByteIdentical($row, $canonical->persistenceAttributes());
        $repository->assertByteIdentical($supplied, $canonical->persistenceAttributes());
    }

    private function findConflictingRow(
        Connection $connection,
        CanonicalSupplierImportSourcePayloadReceipt $receipt,
    ): ?object {
        $rows = $connection->table('supplier_import_source_payload_receipts')
            ->where(function ($query) use ($receipt): void {
                $query->where('supplier_import_source_execution_id', $receipt->sourceExecutionId())
                    ->orWhere('payload_receipt_fingerprint', $receipt->fingerprint());
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($rows->count() > 1) {
            throw new RuntimeException('payload_receipt_identity_conflict');
        }

        return $rows->first();
    }

    private function verifiedModel(
        object $row,
        CanonicalSupplierImportSourcePayloadReceipt $receipt,
        Connection $connection,
    ): SupplierImportSourcePayloadReceipt {
        $stored = CanonicalSupplierImportSourcePayloadReceipt::fromArray([
            'schema' => $row->receipt_version,
            'supplier_import_source_execution_id' => $row->supplier_import_source_execution_id,
            'source_execution_fingerprint' => $row->source_execution_fingerprint,
            'accepted_payload_bytes' => $row->accepted_payload_bytes,
            'accepted_payload_sha256' => $row->accepted_payload_sha256,
        ]);
        if ($stored->fingerprint() !== $row->payload_receipt_fingerprint
            || $stored->canonicalBytes() !== $receipt->canonicalBytes()) {
            throw new RuntimeException('payload_receipt_identity_conflict');
        }

        return (new SupplierImportSourcePayloadReceipt)
            ->setConnection($connection->getName())
            ->newFromBuilder((array) $row);
    }

    private function isEligibleDuplicate(QueryException $exception): bool
    {
        $error = $exception->errorInfo;
        if (($error[0] ?? null) !== '23000' || ($error[1] ?? null) !== 1062
            || ! is_string($error[2] ?? null) || ! str_starts_with($error[2], "Duplicate entry '")) {
            return false;
        }

        foreach (['uq_import_source_payload_receipt_execution', 'uq_import_source_payload_receipt_fingerprint'] as $key) {
            if (str_ends_with($error[2], "' for key 'supplier_import_source_payload_receipts.{$key}'")) {
                return true;
            }
        }

        return false;
    }
}
