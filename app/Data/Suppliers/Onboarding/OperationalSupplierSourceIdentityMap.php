<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;

final class OperationalSupplierSourceIdentityMap
{
    /** @var array<string, string> */
    private array $identities;

    public function __construct(string $primarySupplier, mixed $primarySourceIdentity)
    {
        $this->identities = [
            $primarySupplier => OperationalSupplierSourceIdentity::validate($primarySourceIdentity),
        ];
    }

    public function observe(string $supplier, mixed $sourceIdentity): void
    {
        $sourceIdentity = OperationalSupplierSourceIdentity::validate($sourceIdentity);

        if (! array_key_exists($supplier, $this->identities)) {
            $this->identities[$supplier] = $sourceIdentity;

            return;
        }

        if ($this->identities[$supplier] !== $sourceIdentity) {
            throw new InvalidArgumentException('source_identity_mismatch');
        }
    }

    /** @param array<int, array<string, mixed>> $snapshots */
    public static function assertStable(
        array $snapshots,
        string $primarySupplier,
        mixed $primarySourceIdentity,
    ): string {
        $map = new self($primarySupplier, $primarySourceIdentity);

        foreach ($snapshots as $snapshot) {
            $supplier = $snapshot['supplier'] ?? null;

            if (! is_string($supplier)) {
                throw new InvalidArgumentException('invalid_snapshot_evidence');
            }

            $map->observe($supplier, $snapshot['source_identity'] ?? null);
        }

        return $map->identities[$primarySupplier];
    }
}
