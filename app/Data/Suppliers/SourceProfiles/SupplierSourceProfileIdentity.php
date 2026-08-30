<?php

namespace App\Data\Suppliers\SourceProfiles;

use InvalidArgumentException;
use Stringable;

final readonly class SupplierSourceProfileIdentity implements Stringable
{
    private const PREFIX = 'snapshot-source-v1:profile:';

    private function __construct(private string $value) {}

    public static function fromRandomBytes(string $bytes): self
    {
        if (strlen($bytes) !== 16) {
            throw new InvalidArgumentException('source_profile_identity_requires_16_bytes');
        }

        return new self(self::PREFIX.bin2hex($bytes));
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^snapshot-source-v1:profile:[0-9a-f]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_source_profile_identity');
        }

        return new self($value);
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
