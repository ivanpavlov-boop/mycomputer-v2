<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class OperationalSupplierOfferLifecyclePreviewReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-offer-lifecycle-operational-preview-v1';

    /** @param array<string, mixed> $report */
    public function __construct(public array $report)
    {
        OnboardingValueGuard::assertSafe($this->toArray(), 'operational supplier offer lifecycle preview report');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            ...$this->report,
            'schema_version' => self::SCHEMA_VERSION,
        ]);
    }

    public function canonicalJson(): string
    {
        return CanonicalOnboardingData::encode($this->toArray());
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
