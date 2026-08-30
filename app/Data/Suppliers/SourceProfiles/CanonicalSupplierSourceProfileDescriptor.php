<?php

namespace App\Data\Suppliers\SourceProfiles;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use InvalidArgumentException;
use JsonException;

final readonly class CanonicalSupplierSourceProfileDescriptor
{
    public const VERSION = 'supplier_import_source_profile_v1';

    private const ACCESS_SCOPE_PATTERN = '/^source-access-v1:[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[a-z0-9]+(?:[._-][a-z0-9]+)*)*$/D';

    private const FIELDS = [
        'schema',
        'supplier_id',
        'supplier_feed_id',
        'source_locator_contract_key',
        'source_locator_contract_version',
        'source_locator_key',
        'source_access_scope_key',
        'feed_type',
        'importer_key',
        'importer_version',
        'mapping_contract_version',
        'mapping_contract_fingerprint',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(
        private array $values,
        private CanonicalSupplierSourceLocator $locator,
        private CanonicalSupplierImportMapping $mapping,
    ) {}

    public static function fromContracts(
        int $supplierId,
        int $supplierFeedId,
        CanonicalSupplierSourceLocator $locator,
        string $sourceAccessScopeKey,
        string $feedType,
        string $importerKey,
        string $importerVersion,
        CanonicalSupplierImportMapping $mapping,
    ): self {
        $values = [
            'schema' => self::VERSION,
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $supplierFeedId,
            'source_locator_contract_key' => $locator->contractKey(),
            'source_locator_contract_version' => $locator->contractVersion(),
            'source_locator_key' => $locator->key(),
            'source_access_scope_key' => $sourceAccessScopeKey,
            'feed_type' => $feedType,
            'importer_key' => $importerKey,
            'importer_version' => $importerVersion,
            'mapping_contract_version' => CanonicalSupplierImportMapping::VERSION,
            'mapping_contract_fingerprint' => $mapping->fingerprint(),
        ];

        CanonicalSupplierContract::positiveInteger($supplierId, 'supplier_id');
        CanonicalSupplierContract::positiveInteger($supplierFeedId, 'supplier_feed_id');
        self::assertAccessScope($sourceAccessScopeKey);
        CanonicalSupplierContract::enum($feedType, 'feed_type', ['xml', 'csv']);
        CanonicalSupplierContract::asciiString($importerKey, 'importer_key', 96);
        CanonicalSupplierContract::asciiString($importerVersion, 'importer_version', 32);

        if ($mapping->feedType() !== $feedType) {
            throw new InvalidArgumentException('source_profile_mapping_feed_type_mismatch');
        }

        return new self(CanonicalSupplierContract::ordered($values, self::FIELDS), $locator, $mapping);
    }

    public static function fromCanonicalBytes(
        string $bytes,
        string $expectedFingerprint,
        CanonicalSupplierSourceLocator $locator,
        CanonicalSupplierImportMapping $mapping,
    ): self {
        CanonicalSupplierContract::hex64($expectedFingerprint, 'source_descriptor_fingerprint');
        $values = self::decodeCanonicalBytes($bytes);
        $stringFields = [
            'source_access_scope_key',
            'feed_type',
            'importer_key',
            'importer_version',
        ];

        if (! is_int($values['supplier_id'] ?? null)
            || ! is_int($values['supplier_feed_id'] ?? null)) {
            throw new InvalidArgumentException('invalid_source_profile_descriptor_bytes');
        }

        foreach ($stringFields as $field) {
            if (! is_string($values[$field] ?? null)) {
                throw new InvalidArgumentException('invalid_source_profile_descriptor_bytes');
            }
        }

        $descriptor = self::fromContracts(
            supplierId: $values['supplier_id'],
            supplierFeedId: $values['supplier_feed_id'],
            locator: $locator,
            sourceAccessScopeKey: $values['source_access_scope_key'],
            feedType: $values['feed_type'],
            importerKey: $values['importer_key'],
            importerVersion: $values['importer_version'],
            mapping: $mapping,
        );

        if ($descriptor->canonicalBytes() !== $bytes || $descriptor->fingerprint() !== $expectedFingerprint) {
            throw new InvalidArgumentException('noncanonical_source_profile_descriptor_bytes');
        }

        return $descriptor;
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
            'descriptor_version' => self::VERSION,
            'source_locator_contract_key' => $this->values['source_locator_contract_key'],
            'source_locator_contract_version' => $this->values['source_locator_contract_version'],
            'source_locator_key' => $this->values['source_locator_key'],
            'source_locator_canonical_bytes' => $this->locator->canonicalBytes(),
            'source_access_scope_key' => $this->values['source_access_scope_key'],
            'feed_type' => $this->values['feed_type'],
            'importer_key' => $this->values['importer_key'],
            'importer_version' => $this->values['importer_version'],
            'mapping_contract_version' => CanonicalSupplierImportMapping::VERSION,
            'mapping_canonical_bytes' => $this->mapping->canonicalBytes(),
            'mapping_contract_fingerprint' => $this->values['mapping_contract_fingerprint'],
            'source_descriptor_fingerprint' => $this->fingerprint(),
        ];
    }

    private static function assertAccessScope(string $value): void
    {
        if (strlen($value) < 18
            || strlen($value) > 128
            || preg_match(self::ACCESS_SCOPE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('invalid_source_access_scope_key');
        }
    }

    /** @return array<string, mixed> */
    private static function decodeCanonicalBytes(string $bytes): array
    {
        $prefix = self::VERSION."\0";

        if (! str_starts_with($bytes, $prefix)) {
            throw new InvalidArgumentException('invalid_source_profile_descriptor_bytes');
        }

        try {
            $values = json_decode(substr($bytes, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_source_profile_descriptor_bytes', previous: $exception);
        }

        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('invalid_source_profile_descriptor_bytes');
        }

        return $values;
    }
}
