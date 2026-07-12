<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class AvailabilityNormalizationResult implements JsonSerializable
{
    public function __construct(
        public ?string $externalCode,
        public ?string $externalLabel,
        public ?string $normalizedKey,
        public ?int $quantity,
        public string $mappingConfidence,
        public string $profileVersion,
        public array $warnings = [],
        public array $errors = [],
        public bool $valid = true,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'external_code' => $this->externalCode,
            'external_label' => $this->externalLabel,
            'normalized_key' => $this->normalizedKey,
            'quantity' => $this->quantity,
            'mapping_confidence' => $this->mappingConfidence,
            'profile_version' => $this->profileVersion,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'valid' => $this->valid,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
