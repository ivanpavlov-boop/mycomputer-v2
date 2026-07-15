<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierHumanDecision implements JsonSerializable
{
    public function __construct(
        public string $decisionId,
        public string $subject,
        public string $status,
        public string $sourceFieldOrAction,
        public string $proposedRole,
        public ?string $approvedRole,
        public ?string $evidenceRequirement,
        public ?string $evidenceReference,
        public string $rationale,
        public bool $humanReviewRequired,
        public bool $automaticExecutionAllowed,
        public bool $catalogWriteAllowed,
        public bool $stagingWriteAllowed,
        public bool $profilePersistenceAllowed,
        public bool $blockingDecision,
        public ?string $notes,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier human decision');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'decision_id' => $this->decisionId,
            'subject' => $this->subject,
            'status' => $this->status,
            'source_field_or_action' => $this->sourceFieldOrAction,
            'proposed_role' => $this->proposedRole,
            'approved_role' => $this->approvedRole,
            'evidence_requirement' => $this->evidenceRequirement,
            'evidence_reference' => $this->evidenceReference,
            'rationale' => $this->rationale,
            'human_review_required' => $this->humanReviewRequired,
            'automatic_execution_allowed' => $this->automaticExecutionAllowed,
            'catalog_write_allowed' => $this->catalogWriteAllowed,
            'staging_write_allowed' => $this->stagingWriteAllowed,
            'profile_persistence_allowed' => $this->profilePersistenceAllowed,
            'blocking_decision' => $this->blockingDecision,
            'notes' => $this->notes,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
