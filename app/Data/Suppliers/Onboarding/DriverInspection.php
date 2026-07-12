<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class DriverInspection implements JsonSerializable
{
    public function __construct(
        public bool $compatible,
        public string $sourceFormat,
        public array $detectedFields = [],
        public array $warnings = [],
        public array $issues = [],
        public array $safeMetadata = [],
    ) {
        OnboardingValueGuard::assertSafe($this->safeMetadata, 'driver diagnostics');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'compatible' => $this->compatible,
            'source_format' => $this->sourceFormat,
            'detected_fields' => $this->detectedFields,
            'warnings' => $this->warnings,
            'issues' => $this->issues,
            'safe_metadata' => $this->safeMetadata,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
