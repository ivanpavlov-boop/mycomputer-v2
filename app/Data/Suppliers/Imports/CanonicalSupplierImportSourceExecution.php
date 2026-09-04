<?php

namespace App\Data\Suppliers\Imports;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use InvalidArgumentException;
use JsonException;

final readonly class CanonicalSupplierImportSourceExecution
{
    public const VERSION = 'supplier_import_source_execution_v1';

    private const FIELDS = [
        'schema',
        'supplier_id',
        'supplier_feed_id',
        'import_job_id',
        'import_history_id',
        'supplier_import_source_profile_id',
        'source_identity',
        'source_descriptor_version',
        'source_descriptor_fingerprint',
        'import_job_identity_version',
        'import_job_identity_fingerprint',
        'resolved_source_context_version',
        'source_locator_contract_key',
        'source_locator_contract_version',
        'source_locator_key',
        'source_access_scope_key',
        'feed_type',
        'importer_key',
        'importer_version',
        'mapping_contract_version',
        'mapping_contract_fingerprint',
        'captured_at',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(
        private array $values,
        private ImportJobIdentity $jobIdentity,
    ) {}

    public static function fromContracts(
        ImportJobIdentity $jobIdentity,
        ResolvedSupplierImportSourceContext $context,
        int $importHistoryId,
        string $capturedAt,
    ): self {
        $contextValues = $context->toCanonicalArray();

        if ($jobIdentity->supplierId() !== $context->supplierId()
            || $jobIdentity->supplierFeedId() !== $context->supplierFeedId()
            || $jobIdentity->importType() !== $contextValues['feed_type']) {
            throw new InvalidArgumentException('source_execution_ownership_mismatch');
        }

        $values = [
            'schema' => self::VERSION,
            'supplier_id' => $context->supplierId(),
            'supplier_feed_id' => $context->supplierFeedId(),
            'import_job_id' => $jobIdentity->importJobId(),
            'import_history_id' => $importHistoryId,
            'supplier_import_source_profile_id' => $context->sourceProfileId(),
            'source_identity' => $context->sourceIdentity(),
            'source_descriptor_version' => $contextValues['source_descriptor_version'],
            'source_descriptor_fingerprint' => $context->sourceDescriptorFingerprint(),
            'import_job_identity_version' => ImportJobIdentity::VERSION,
            'import_job_identity_fingerprint' => $jobIdentity->fingerprint(),
            'resolved_source_context_version' => ResolvedSupplierImportSourceContext::VERSION,
            'source_locator_contract_key' => $contextValues['source_locator_contract_key'],
            'source_locator_contract_version' => $contextValues['source_locator_contract_version'],
            'source_locator_key' => $contextValues['source_locator_key'],
            'source_access_scope_key' => $contextValues['source_access_scope_key'],
            'feed_type' => $contextValues['feed_type'],
            'importer_key' => $context->importerKey(),
            'importer_version' => $context->importerVersion(),
            'mapping_contract_version' => $contextValues['mapping_contract_version'],
            'mapping_contract_fingerprint' => $contextValues['mapping_contract_fingerprint'],
            'captured_at' => $capturedAt,
        ];

        CanonicalSupplierContract::positiveInteger($importHistoryId, 'import_history_id');
        CanonicalSupplierContract::mysqlUtcMicroseconds($capturedAt, 'captured_at');

        if ($values['source_descriptor_version'] !== CanonicalSupplierSourceProfileDescriptor::VERSION
            || $values['mapping_contract_version'] !== CanonicalSupplierImportMapping::VERSION) {
            throw new InvalidArgumentException('source_execution_contract_version_mismatch');
        }

        return new self(CanonicalSupplierContract::ordered($values, self::FIELDS), $jobIdentity);
    }

    public static function fromCanonicalBytes(
        string $bytes,
        string $expectedFingerprint,
        ImportJobIdentity $jobIdentity,
        ResolvedSupplierImportSourceContext $context,
    ): self {
        CanonicalSupplierContract::hex64($expectedFingerprint, 'source_execution_fingerprint');
        $values = self::decodeCanonicalBytes($bytes);

        if (! is_int($values['import_history_id'] ?? null)
            || ! is_string($values['captured_at'] ?? null)) {
            throw new InvalidArgumentException('invalid_source_execution_bytes');
        }

        $execution = self::fromContracts(
            $jobIdentity,
            $context,
            $values['import_history_id'],
            $values['captured_at'],
        );

        if ($execution->canonicalBytes() !== $bytes || $execution->fingerprint() !== $expectedFingerprint) {
            throw new InvalidArgumentException('noncanonical_source_execution_bytes');
        }

        return $execution;
    }

    /** @return array<string, mixed> */
    public function toCanonicalArray(): array
    {
        return $this->values;
    }

    public function canonicalBytes(): string
    {
        return self::VERSION."\0".CanonicalSupplierContract::encodeSorted($this->values);
    }

    public function fingerprint(): string
    {
        return CanonicalSupplierContract::rawDigest($this->canonicalBytes());
    }

    /** @return array<string, int|string> */
    public function persistenceAttributes(): array
    {
        return [
            'supplier_id' => $this->values['supplier_id'],
            'supplier_feed_id' => $this->values['supplier_feed_id'],
            'import_job_id' => $this->values['import_job_id'],
            'import_history_id' => $this->values['import_history_id'],
            'supplier_import_source_profile_id' => $this->values['supplier_import_source_profile_id'],
            'source_identity' => $this->values['source_identity'],
            'source_descriptor_fingerprint' => $this->values['source_descriptor_fingerprint'],
            'importer_key' => $this->values['importer_key'],
            'importer_version' => $this->values['importer_version'],
            'import_job_identity_version' => ImportJobIdentity::VERSION,
            'import_job_identity_canonical_bytes' => $this->jobIdentity->canonicalBytes(),
            'import_job_identity_fingerprint' => $this->jobIdentity->fingerprint(),
            'resolved_source_context_version' => ResolvedSupplierImportSourceContext::VERSION,
            'captured_at' => str_replace(['T', 'Z'], [' ', ''], $this->values['captured_at']),
            'source_execution_fingerprint' => $this->fingerprint(),
        ];
    }

    public function capturedAt(): string
    {
        return $this->values['captured_at'];
    }

    /** @return array<string, mixed> */
    private static function decodeCanonicalBytes(string $bytes): array
    {
        $prefix = self::VERSION."\0";

        if (! str_starts_with($bytes, $prefix)) {
            throw new InvalidArgumentException('invalid_source_execution_bytes');
        }

        try {
            $values = json_decode(substr($bytes, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_source_execution_bytes', previous: $exception);
        }

        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('invalid_source_execution_bytes');
        }

        return $values;
    }
}
