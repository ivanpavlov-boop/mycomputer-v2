<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierOfferLifecycleApprovalGate implements JsonSerializable
{
    /** @param array<int, string> $unresolvedPolicyReasons */
    public function __construct(
        public string $decisionRegisterKey,
        public string $previewProfileKey,
        public array $unresolvedPolicyReasons,
        public bool $structuralValidation,
        public bool $missingOfferPolicyConfirmed,
        public bool $catalogVisibilityPolicyConfirmed,
        public bool $technicalRetentionPolicyConfirmed,
        public bool $semanticConfirmationComplete,
        public bool $operationalImportApproval,
        public bool $profilePersistenceApproval,
        public bool $offerLifecycleWriteApproval,
        public bool $productVisibilityWriteApproval,
        public bool $scheduleEnablementApproval,
        public bool $catalogSyncApproval,
        public string $gateStatus,
        public bool $humanReviewRequired,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier offer lifecycle approval gate');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'catalog_sync_approval' => $this->catalogSyncApproval,
            'catalog_visibility_policy_confirmed' => $this->catalogVisibilityPolicyConfirmed,
            'decision_register_key' => $this->decisionRegisterKey,
            'gate_status' => $this->gateStatus,
            'human_review_required' => $this->humanReviewRequired,
            'missing_offer_policy_confirmed' => $this->missingOfferPolicyConfirmed,
            'offer_lifecycle_write_approval' => $this->offerLifecycleWriteApproval,
            'operational_import_approval' => $this->operationalImportApproval,
            'product_visibility_write_approval' => $this->productVisibilityWriteApproval,
            'profile_persistence_approval' => $this->profilePersistenceApproval,
            'preview_profile_key' => $this->previewProfileKey,
            'schedule_enablement_approval' => $this->scheduleEnablementApproval,
            'semantic_confirmation_complete' => $this->semanticConfirmationComplete,
            'structural_validation' => $this->structuralValidation,
            'technical_retention_policy_confirmed' => $this->technicalRetentionPolicyConfirmed,
            'unresolved_policy_reasons' => $this->unresolvedPolicyReasons,
            'valid' => true,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
