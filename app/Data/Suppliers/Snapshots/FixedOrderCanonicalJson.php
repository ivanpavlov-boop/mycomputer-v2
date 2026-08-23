<?php

namespace App\Data\Suppliers\Snapshots;

abstract readonly class FixedOrderCanonicalJson
{
    /** @param array<string, mixed> $values */
    final protected function __construct(private array $values) {}

    /** @return list<string> */
    final public static function fields(): array
    {
        return static::FIELDS;
    }

    /** @return array<string, mixed> */
    final public function toCanonicalArray(): array
    {
        return $this->values;
    }

    final public function canonicalBytes(): string
    {
        return CanonicalSupplierContract::encodeFixed($this->values);
    }

    final public function fingerprint(): string
    {
        return CanonicalSupplierContract::digest(static::DOMAIN, $this->canonicalBytes());
    }
}
