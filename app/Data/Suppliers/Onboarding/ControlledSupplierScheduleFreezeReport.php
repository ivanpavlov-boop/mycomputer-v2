<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class ControlledSupplierScheduleFreezeReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'controlled-supplier-schedule-freeze-v1';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private array $payload)
    {
        OnboardingValueGuard::assertSafe($this->payload, 'controlled supplier schedule freeze report');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            ...$this->payload,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
