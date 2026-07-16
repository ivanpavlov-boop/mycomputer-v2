<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierPreviewFeedProfileDesign implements JsonSerializable
{
    /** @param array<int, array<string, mixed>> $fieldMappings @param array<int, array<string, mixed>> $actionMatrix */
    public function __construct(
        public string $key,
        public string $supplierKey,
        public string $decisionRegisterKey,
        public string $semanticsProfileKey,
        public array $fieldMappings,
        public array $actionMatrix,
        public array $safetyPolicy = [],
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier preview feed profile design');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'key' => $this->key,
            'supplier_key' => $this->supplierKey,
            'decision_register_key' => $this->decisionRegisterKey,
            'semantics_profile_key' => $this->semanticsProfileKey,
            'field_mappings' => $this->fieldMappings,
            'action_matrix' => $this->actionMatrix,
            'read_only' => true,
            'persisted' => false,
            'executable' => false,
        ];

        if ($this->safetyPolicy !== []) {
            $payload['safety_policy'] = $this->safetyPolicy;
        }

        return CanonicalOnboardingData::normalize($payload);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
