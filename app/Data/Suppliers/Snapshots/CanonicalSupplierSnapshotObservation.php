<?php

namespace App\Data\Suppliers\Snapshots;

use InvalidArgumentException;

final readonly class CanonicalSupplierSnapshotObservation
{
    private const FIELDS = [
        'supplier_sku_hash',
        'present',
        'price',
        'currency',
        'raw_quantity_observed',
        'eol_flag',
        'canonical_public_status',
        'supplier_mapper_valid',
        'exact_supplier_sku_match',
        'identifier_conflict',
        'blocking_validation_issue',
        'duplicate_offer',
        'reliable_manufacturer_mpn_hash',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        CanonicalSupplierContract::hex64($values['supplier_sku_hash'], 'supplier_sku_hash');
        CanonicalSupplierContract::boolean($values['present'], 'present');
        CanonicalSupplierContract::exactPrice($values['price'], 'price');
        self::currency($values['currency']);
        CanonicalSupplierContract::nullableUnsignedInteger(
            $values['raw_quantity_observed'],
            'raw_quantity_observed',
        );
        self::eolFlag($values['eol_flag']);

        if ($values['canonical_public_status'] !== null) {
            CanonicalSupplierContract::asciiString(
                $values['canonical_public_status'],
                'canonical_public_status',
                48,
            );
        }

        foreach ([
            'supplier_mapper_valid',
            'exact_supplier_sku_match',
            'identifier_conflict',
            'blocking_validation_issue',
            'duplicate_offer',
        ] as $field) {
            CanonicalSupplierContract::boolean($values[$field], $field);
        }

        if ($values['reliable_manufacturer_mpn_hash'] !== null) {
            throw new InvalidArgumentException('unapproved_reliable_manufacturer_mpn_hash');
        }

        if (! $values['present']) {
            self::assertAbsentSemantics($values);
        }

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

    private static function currency(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_currency');
        }

        return $value;
    }

    private static function eolFlag(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || ! in_array($value, [0, 1], true)) {
            throw new InvalidArgumentException('invalid_eol_flag');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function assertAbsentSemantics(array $values): void
    {
        foreach ([
            'price',
            'currency',
            'raw_quantity_observed',
            'eol_flag',
            'canonical_public_status',
            'reliable_manufacturer_mpn_hash',
        ] as $field) {
            if ($values[$field] !== null) {
                throw new InvalidArgumentException('invalid_absent_observation');
            }
        }

        foreach ([
            'supplier_mapper_valid',
            'exact_supplier_sku_match',
            'identifier_conflict',
            'blocking_validation_issue',
            'duplicate_offer',
        ] as $field) {
            if ($values[$field]) {
                throw new InvalidArgumentException('invalid_absent_observation');
            }
        }
    }
}
