<?php

namespace App\Data\Suppliers\Onboarding;

use App\Enums\Suppliers\Onboarding\CanonicalPublicAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierLifecycleStatus;
use JsonSerializable;

final readonly class SupplierAvailabilityMappingResult implements JsonSerializable
{
    /** @param array<int, SupplierAvailabilityEvidence> $evidence */
    public function __construct(
        public string $supplierKey,
        public string $policyKey,
        public CanonicalSupplierAvailabilityStatus $canonicalAvailabilityStatus,
        public CanonicalSupplierLifecycleStatus $canonicalLifecycleStatus,
        public CanonicalPublicAvailabilityStatus $canonicalPublicStatus,
        public int $rawQuantityObserved,
        public bool $quantityIsCapped,
        public ?int $quantityMinimum,
        public bool $exactPublicQuantityAllowed,
        public int $lowStockThreshold,
        public bool $requiresHumanReview,
        public bool $requiresAvailabilityConfirmation,
        public bool $orderableInPrinciple,
        public array $evidence,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier availability mapping result');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $evidence = array_map(static fn (SupplierAvailabilityEvidence $item): array => $item->toArray(), $this->evidence);

        return CanonicalOnboardingData::normalize([
            'automatic_execution_allowed' => false,
            'canonical_availability_status' => $this->canonicalAvailabilityStatus->value,
            'canonical_lifecycle_status' => $this->canonicalLifecycleStatus->value,
            'canonical_public_status' => $this->canonicalPublicStatus->value,
            'catalog_write_allowed' => false,
            'evidence' => $evidence,
            'evidence_codes' => array_values(array_map(static fn (SupplierAvailabilityEvidence $item): string => $item->code, $this->evidence)),
            'exact_public_quantity_allowed' => $this->exactPublicQuantityAllowed,
            'import_allowed' => false,
            'low_stock_threshold' => $this->lowStockThreshold,
            'orderable_in_principle' => $this->orderableInPrinciple,
            'policy_key' => $this->policyKey,
            'profile_persistence_allowed' => false,
            'quantity_is_capped' => $this->quantityIsCapped,
            'quantity_minimum' => $this->quantityMinimum,
            'raw_quantity_observed' => $this->rawQuantityObserved,
            'requires_availability_confirmation' => $this->requiresAvailabilityConfirmation,
            'requires_human_review' => $this->requiresHumanReview,
            'schedule_enablement_allowed' => false,
            'staging_write_allowed' => false,
            'supplier_key' => $this->supplierKey,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
