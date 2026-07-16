<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierAvailabilityEvidence implements JsonSerializable
{
    public function __construct(
        public string $code,
        public string $type,
        public string $description,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier availability evidence');
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
