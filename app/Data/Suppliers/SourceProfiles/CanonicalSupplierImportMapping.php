<?php

namespace App\Data\Suppliers\SourceProfiles;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use InvalidArgumentException;
use JsonException;

final readonly class CanonicalSupplierImportMapping
{
    public const VERSION = 'supplier_import_mapping_contract_v1';

    private const FIELDS = [
        'schema',
        'feed_type',
        'effective_mapping',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::VERSION) {
            throw new InvalidArgumentException('invalid_mapping_contract_version');
        }

        CanonicalSupplierContract::enum($values['feed_type'], 'feed_type', ['xml', 'csv']);
        CanonicalSupplierContract::canonicalObject($values['effective_mapping'], 'effective_mapping');

        return new self($values);
    }

    public static function fromCanonicalBytes(string $bytes, string $expectedFingerprint): self
    {
        CanonicalSupplierContract::hex64($expectedFingerprint, 'mapping_contract_fingerprint');
        $values = self::decodeCanonicalBytes($bytes);
        $mapping = self::fromArray($values);

        if ($mapping->canonicalBytes() !== $bytes || $mapping->fingerprint() !== $expectedFingerprint) {
            throw new InvalidArgumentException('noncanonical_mapping_contract_bytes');
        }

        return $mapping;
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

    public function feedType(): string
    {
        return $this->values['feed_type'];
    }

    /** @return array<string, mixed> */
    private static function decodeCanonicalBytes(string $bytes): array
    {
        $prefix = self::VERSION."\0";

        if (! str_starts_with($bytes, $prefix)) {
            throw new InvalidArgumentException('invalid_mapping_contract_bytes');
        }

        try {
            $values = json_decode(substr($bytes, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_mapping_contract_bytes', previous: $exception);
        }

        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('invalid_mapping_contract_bytes');
        }

        return $values;
    }
}
