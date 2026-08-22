<?php

namespace App\Data\Suppliers\Snapshots;

use App\Data\Suppliers\Onboarding\SnapshotSourceIdentity;
use InvalidArgumentException;

final readonly class CanonicalSupplierSnapshotEnrollment
{
    private const FIELDS = [
        'supplier_key',
        'source_identity',
        'supplier_sku_hash',
        'effective_import_history_id',
        'enrollment_source',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);
        $supplierKey = CanonicalSupplierContract::asciiString($values['supplier_key'], 'supplier_key', 96);

        if ($supplierKey !== strtolower(trim($supplierKey))) {
            throw new InvalidArgumentException('invalid_supplier_key');
        }

        SnapshotSourceIdentity::validate($values['source_identity']);
        CanonicalSupplierContract::hex64($values['supplier_sku_hash'], 'supplier_sku_hash');
        CanonicalSupplierContract::positiveInteger(
            $values['effective_import_history_id'],
            'effective_import_history_id',
        );
        CanonicalSupplierContract::enum($values['enrollment_source'], 'enrollment_source', [
            'capture_start_seed',
            'exact_source_observation',
            'capture_start_seed_and_exact_source_observation',
        ]);

        return new self($values);
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /** @return array<string, mixed> */
    public function toCanonicalArray(): array
    {
        return $this->values;
    }

    public function canonicalBytes(): string
    {
        return CanonicalSupplierContract::encodeSorted($this->values);
    }
}
