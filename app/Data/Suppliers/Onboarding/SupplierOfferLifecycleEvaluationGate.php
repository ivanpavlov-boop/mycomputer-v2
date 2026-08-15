<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierOfferLifecycleEvaluationGate implements JsonSerializable
{
    public function __construct(
        public string $decisionRegisterKey,
        public string $previewProfileKey,
        public bool $evaluationAllowed,
        public string $gateStatus = 'evaluation_only',
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier offer lifecycle evaluation gate');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'automatic_execution_allowed' => false,
            'catalog_sync_allowed' => false,
            'catalog_write_allowed' => false,
            'decision_register_key' => $this->decisionRegisterKey,
            'evaluation_allowed' => $this->evaluationAllowed,
            'gate_status' => $this->gateStatus,
            'import_allowed' => false,
            'lifecycle_write_allowed' => false,
            'link_change_allowed' => false,
            'preview_profile_key' => $this->previewProfileKey,
            'profile_persistence_allowed' => false,
            'schedule_change_allowed' => false,
            'staging_write_allowed' => false,
            'visibility_write_allowed' => false,
            'write_allowed' => false,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
