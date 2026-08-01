<?php

namespace App\Services\CartRecovery;

use Carbon\CarbonImmutable;
use LogicException;
use SensitiveParameter;

final readonly class IssuedCartRecoveryCapability
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
        private string $hash,
        private string $url,
        private CarbonImmutable $expiresAt,
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function __debugInfo(): array
    {
        return ['capability' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Recovery capabilities cannot be serialized.');
    }
}
