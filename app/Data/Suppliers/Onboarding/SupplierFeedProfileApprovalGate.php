<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierFeedProfileApprovalGate implements JsonSerializable
{
    /**
     * @param  array<int, string>  $approvedDecisionIds
     * @param  array<int, string>  $pendingDecisionIds
     * @param  array<int, string>  $reviewOnlyDecisionIds
     * @param  array<int, string>  $prohibitedActionIds
     * @param  array<int, string>  $blockingDecisionIds
     * @param  array<int, string>  $unresolvedPolicyReasons
     */
    public function __construct(
        public string $profileKey,
        public string $decisionRegisterKey,
        public array $approvedDecisionIds,
        public array $pendingDecisionIds,
        public array $reviewOnlyDecisionIds,
        public array $prohibitedActionIds,
        public array $blockingDecisionIds,
        public array $unresolvedPolicyReasons,
        public bool $semanticConfirmationComplete,
        public bool $operationalImportApproval,
        public bool $profilePersistenceApproval,
        public bool $scheduleEnablementApproval,
        public bool $catalogSyncApproval,
        public string $gateStatus,
        public bool $humanReviewRequired,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier feed profile approval gate');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'approved_decision_ids' => $this->approvedDecisionIds,
            'blocking_decision_ids' => $this->blockingDecisionIds,
            'catalog_sync_approval' => $this->catalogSyncApproval,
            'decision_register_key' => $this->decisionRegisterKey,
            'gate_status' => $this->gateStatus,
            'human_review_required' => $this->humanReviewRequired,
            'operational_import_approval' => $this->operationalImportApproval,
            'pending_decision_ids' => $this->pendingDecisionIds,
            'profile_key' => $this->profileKey,
            'profile_persistence_approval' => $this->profilePersistenceApproval,
            'prohibited_action_ids' => $this->prohibitedActionIds,
            'review_only_decision_ids' => $this->reviewOnlyDecisionIds,
            'schedule_enablement_approval' => $this->scheduleEnablementApproval,
            'semantic_confirmation_complete' => $this->semanticConfirmationComplete,
            'unresolved_policy_reasons' => $this->unresolvedPolicyReasons,
            'valid' => true,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
