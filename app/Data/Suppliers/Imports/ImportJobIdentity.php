<?php

namespace App\Data\Suppliers\Imports;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use InvalidArgumentException;
use JsonException;

final readonly class ImportJobIdentity
{
    public const VERSION = 'supplier_import_job_identity_v1';

    private const FIELDS = [
        'schema',
        'import_job_id',
        'supplier_id',
        'supplier_feed_id',
        'xml_mapping_template_id',
        'import_type',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::VERSION) {
            throw new InvalidArgumentException('invalid_import_job_identity_version');
        }

        CanonicalSupplierContract::positiveInteger($values['import_job_id'], 'import_job_id');
        CanonicalSupplierContract::positiveInteger($values['supplier_id'], 'supplier_id');
        CanonicalSupplierContract::positiveInteger($values['supplier_feed_id'], 'supplier_feed_id');
        CanonicalSupplierContract::nullablePositiveInteger(
            $values['xml_mapping_template_id'],
            'xml_mapping_template_id',
        );
        CanonicalSupplierContract::enum($values['import_type'], 'import_type', ['xml', 'csv']);

        if (($values['import_type'] === 'xml') !== ($values['xml_mapping_template_id'] !== null)) {
            throw new InvalidArgumentException('invalid_import_job_template_selector');
        }

        return new self($values);
    }

    public static function fromCanonicalBytes(string $bytes, string $expectedFingerprint): self
    {
        CanonicalSupplierContract::hex64($expectedFingerprint, 'import_job_identity_fingerprint');
        $identity = self::fromArray(self::decodeCanonicalBytes($bytes));

        if ($identity->canonicalBytes() !== $bytes || $identity->fingerprint() !== $expectedFingerprint) {
            throw new InvalidArgumentException('noncanonical_import_job_identity_bytes');
        }

        return $identity;
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

    public function importJobId(): int
    {
        return $this->values['import_job_id'];
    }

    public function supplierId(): int
    {
        return $this->values['supplier_id'];
    }

    public function supplierFeedId(): int
    {
        return $this->values['supplier_feed_id'];
    }

    public function importType(): string
    {
        return $this->values['import_type'];
    }

    /** @return array<string, mixed> */
    private static function decodeCanonicalBytes(string $bytes): array
    {
        $prefix = self::VERSION."\0";

        if (! str_starts_with($bytes, $prefix)) {
            throw new InvalidArgumentException('invalid_import_job_identity_bytes');
        }

        try {
            $values = json_decode(substr($bytes, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_import_job_identity_bytes', previous: $exception);
        }

        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('invalid_import_job_identity_bytes');
        }

        return $values;
    }
}
