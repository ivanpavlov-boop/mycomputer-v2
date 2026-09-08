<?php

namespace App\Data\Suppliers\Imports;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use InvalidArgumentException;
use JsonException;

final readonly class CanonicalSupplierImportSourcePayloadReceipt
{
    public const VERSION = 'supplier_import_source_payload_receipt_v1';

    private const FIELDS = [
        'schema',
        'supplier_import_source_execution_id',
        'source_execution_fingerprint',
        'accepted_payload_bytes',
        'accepted_payload_sha256',
    ];

    /** @param array<string, int|string> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::VERSION) {
            throw new InvalidArgumentException('invalid_payload_receipt_version');
        }

        CanonicalSupplierContract::positiveInteger($values['supplier_import_source_execution_id'], 'source_execution_id');
        CanonicalSupplierContract::positiveInteger($values['accepted_payload_bytes'], 'accepted_payload_bytes');
        CanonicalSupplierContract::hex64($values['source_execution_fingerprint'], 'source_execution_fingerprint');
        CanonicalSupplierContract::hex64($values['accepted_payload_sha256'], 'accepted_payload_sha256');

        return new self($values);
    }

    public static function fromCanonicalBytes(string $bytes, string $expectedFingerprint): self
    {
        CanonicalSupplierContract::hex64($expectedFingerprint, 'payload_receipt_fingerprint');
        $prefix = self::VERSION."\0";
        if (! str_starts_with($bytes, $prefix)) {
            throw new InvalidArgumentException('invalid_payload_receipt_bytes');
        }

        try {
            $values = json_decode(substr($bytes, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid_payload_receipt_bytes');
        }

        if (! is_array($values)) {
            throw new InvalidArgumentException('invalid_payload_receipt_bytes');
        }

        $receipt = self::fromArray($values);
        if ($receipt->canonicalBytes() !== $bytes || $receipt->fingerprint() !== $expectedFingerprint) {
            throw new InvalidArgumentException('noncanonical_payload_receipt_bytes');
        }

        return $receipt;
    }

    /** @return array<string, int|string> */
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

    public function sourceExecutionId(): int
    {
        return $this->values['supplier_import_source_execution_id'];
    }

    public function sourceExecutionFingerprint(): string
    {
        return $this->values['source_execution_fingerprint'];
    }

    /** @return array<string, int|string> */
    public function persistenceAttributes(): array
    {
        return [
            'supplier_import_source_execution_id' => $this->sourceExecutionId(),
            'source_execution_fingerprint' => $this->sourceExecutionFingerprint(),
            'receipt_version' => self::VERSION,
            'accepted_payload_bytes' => $this->values['accepted_payload_bytes'],
            'accepted_payload_sha256' => $this->values['accepted_payload_sha256'],
            'payload_receipt_fingerprint' => $this->fingerprint(),
        ];
    }
}
