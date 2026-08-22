<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use Stringable;

final readonly class SnapshotSourceIdentity implements Stringable
{
    private const PATTERN = '/^snapshot-source-v1:[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[a-z0-9]+(?:[._-][a-z0-9]+)*)*$/D';

    private const MAX_BYTES = 128;

    public function __construct(private string $value)
    {
        self::validate($value);
    }

    public static function from(mixed $value): self
    {
        return new self(self::validate($value));
    }

    public static function validate(mixed $value): string
    {
        $identity = OperationalSupplierSourceIdentity::validate($value);

        if (strlen($identity) > self::MAX_BYTES || preg_match(self::PATTERN, $identity) !== 1) {
            throw new InvalidArgumentException('invalid_snapshot_source_identity');
        }

        return $identity;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
