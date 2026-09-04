<?php

namespace App\Data\Suppliers\Imports;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Data\Suppliers\SourceProfiles\SupplierSourceProfileIdentity;
use App\Models\SupplierImportSourceProfile;
use InvalidArgumentException;
use JsonException;

final readonly class ResolvedSupplierImportSourceContext
{
    public const VERSION = 'supplier_import_resolved_source_context_v1';

    private const FIELDS = [
        'schema',
        'source_profile_id',
        'source_identity',
        'source_descriptor_version',
        'source_descriptor_fingerprint',
        'supplier_id',
        'supplier_feed_id',
        'source_locator_contract_key',
        'source_locator_contract_version',
        'source_locator_key',
        'source_locator_canonical_bytes',
        'source_access_scope_key',
        'feed_type',
        'importer_key',
        'importer_version',
        'mapping_contract_version',
        'mapping_canonical_bytes',
        'mapping_contract_fingerprint',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    public static function fromProfile(SupplierImportSourceProfile $profile): self
    {
        return self::fromArray([
            'schema' => self::VERSION,
            'source_profile_id' => (int) $profile->getAttribute('id'),
            'source_identity' => $profile->getAttribute('source_identity'),
            'source_descriptor_version' => $profile->getAttribute('descriptor_version'),
            'source_descriptor_fingerprint' => $profile->getAttribute('source_descriptor_fingerprint'),
            'supplier_id' => (int) $profile->getAttribute('supplier_id'),
            'supplier_feed_id' => (int) $profile->getAttribute('supplier_feed_id'),
            'source_locator_contract_key' => $profile->getAttribute('source_locator_contract_key'),
            'source_locator_contract_version' => $profile->getAttribute('source_locator_contract_version'),
            'source_locator_key' => $profile->getAttribute('source_locator_key'),
            'source_locator_canonical_bytes' => $profile->getAttribute('source_locator_canonical_bytes'),
            'source_access_scope_key' => $profile->getAttribute('source_access_scope_key'),
            'feed_type' => $profile->getAttribute('feed_type'),
            'importer_key' => $profile->getAttribute('importer_key'),
            'importer_version' => $profile->getAttribute('importer_version'),
            'mapping_contract_version' => $profile->getAttribute('mapping_contract_version'),
            'mapping_canonical_bytes' => $profile->getAttribute('mapping_canonical_bytes'),
            'mapping_contract_fingerprint' => $profile->getAttribute('mapping_contract_fingerprint'),
        ]);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::VERSION) {
            throw new InvalidArgumentException('invalid_resolved_source_context_version');
        }

        CanonicalSupplierContract::positiveInteger($values['source_profile_id'], 'source_profile_id');
        CanonicalSupplierContract::positiveInteger($values['supplier_id'], 'supplier_id');
        CanonicalSupplierContract::positiveInteger($values['supplier_feed_id'], 'supplier_feed_id');
        SupplierSourceProfileIdentity::fromString($values['source_identity']);

        if ($values['source_descriptor_version'] !== CanonicalSupplierSourceProfileDescriptor::VERSION
            || $values['mapping_contract_version'] !== CanonicalSupplierImportMapping::VERSION) {
            throw new InvalidArgumentException('invalid_resolved_source_context_contract_version');
        }

        CanonicalSupplierContract::hex64(
            $values['source_descriptor_fingerprint'],
            'source_descriptor_fingerprint',
        );
        CanonicalSupplierContract::hex64(
            $values['mapping_contract_fingerprint'],
            'mapping_contract_fingerprint',
        );

        if (! is_string($values['source_locator_canonical_bytes'])
            || ! is_string($values['mapping_canonical_bytes'])) {
            throw new InvalidArgumentException('invalid_resolved_source_context_bytes');
        }

        $locator = CanonicalSupplierSourceLocator::fromCanonicalBytes(
            $values['source_locator_canonical_bytes'],
            $values['source_locator_key'],
        );
        $mapping = CanonicalSupplierImportMapping::fromCanonicalBytes(
            $values['mapping_canonical_bytes'],
            $values['mapping_contract_fingerprint'],
        );
        $descriptor = CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: $values['supplier_id'],
            supplierFeedId: $values['supplier_feed_id'],
            locator: $locator,
            sourceAccessScopeKey: $values['source_access_scope_key'],
            feedType: $values['feed_type'],
            importerKey: $values['importer_key'],
            importerVersion: $values['importer_version'],
            mapping: $mapping,
        );

        if ($locator->contractKey() !== $values['source_locator_contract_key']
            || $locator->contractVersion() !== $values['source_locator_contract_version']
            || $descriptor->fingerprint() !== $values['source_descriptor_fingerprint']) {
            throw new InvalidArgumentException('contradictory_resolved_source_context');
        }

        return new self($values);
    }

    public static function fromCanonicalBytes(string $bytes, string $expectedFingerprint): self
    {
        CanonicalSupplierContract::hex64($expectedFingerprint, 'resolved_source_context_fingerprint');
        $context = self::fromArray(self::decodeCanonicalBytes($bytes));

        if ($context->canonicalBytes() !== $bytes || $context->fingerprint() !== $expectedFingerprint) {
            throw new InvalidArgumentException('noncanonical_resolved_source_context_bytes');
        }

        return $context;
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

    public function sourceProfileId(): int
    {
        return $this->values['source_profile_id'];
    }

    public function sourceIdentity(): string
    {
        return $this->values['source_identity'];
    }

    public function sourceDescriptorFingerprint(): string
    {
        return $this->values['source_descriptor_fingerprint'];
    }

    public function supplierId(): int
    {
        return $this->values['supplier_id'];
    }

    public function supplierFeedId(): int
    {
        return $this->values['supplier_feed_id'];
    }

    public function importerKey(): string
    {
        return $this->values['importer_key'];
    }

    public function importerVersion(): string
    {
        return $this->values['importer_version'];
    }

    /** @return array<string, mixed> */
    private static function decodeCanonicalBytes(string $bytes): array
    {
        $prefix = self::VERSION."\0";

        if (! str_starts_with($bytes, $prefix)) {
            throw new InvalidArgumentException('invalid_resolved_source_context_bytes');
        }

        try {
            $values = json_decode(substr($bytes, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_resolved_source_context_bytes', previous: $exception);
        }

        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('invalid_resolved_source_context_bytes');
        }

        return $values;
    }
}
