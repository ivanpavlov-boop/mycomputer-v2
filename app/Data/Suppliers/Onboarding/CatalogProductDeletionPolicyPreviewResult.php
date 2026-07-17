<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class CatalogProductDeletionPolicyPreviewResult implements JsonSerializable
{
    /** @param array<int, string> $reasonCodes */
    public function __construct(
        public string $productReferenceHash,
        public string $manualReviewClassification,
        public array $reasonCodes,
        public bool $requiresSuperAdminReview,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'catalog product deletion policy preview result');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'automatic_hard_delete_allowed' => false,
            'automatic_soft_delete_allowed' => false,
            'delete_allowed' => false,
            'manual_review_classification' => $this->manualReviewClassification,
            'product_reference_hash' => $this->productReferenceHash,
            'reason_codes' => $this->reasonCodes,
            'records_changed' => 0,
            'requires_super_admin_review' => $this->requiresSuperAdminReview,
            'write_allowed' => false,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
