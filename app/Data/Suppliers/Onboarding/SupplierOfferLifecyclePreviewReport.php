<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierOfferLifecyclePreviewReport implements JsonSerializable
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload)
    {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier offer lifecycle preview report');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'mode' => 'preview_only',
            'preview_only' => true,
            'read_only' => true,
            'schema_version' => 'supplier-offer-lifecycle-preview-v1',
            'success' => true,
            'verdict' => 'offer_lifecycle_policy_confirmed_execution_blocked',
            ...$this->payload,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
