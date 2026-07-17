<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierOfferReappearanceInput;
use App\Data\Suppliers\Onboarding\SupplierOfferReappearancePreviewResult;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationResult;

final class SupplierOfferReappearancePolicy
{
    public const POLICY_KEY = 'supplier-offer-reappearance-policy-v1';

    public function preview(
        SupplierOfferReappearanceInput $input,
        SupplierSnapshotQualificationResult $qualification,
    ): SupplierOfferReappearancePreviewResult {
        $reasons = array_values(array_filter([
            ! $qualification->qualifiesForPresenceTracking ? 'snapshot_not_qualified' : null,
            ! $input->supplierSkuMatchesExactly ? 'supplier_sku_mismatch' : null,
            $input->price <= 0 ? 'zero_or_invalid_price' : null,
            ! $input->supplierMapperValid ? 'supplier_mapper_validation_failed' : null,
            $input->hasIdentifierConflict ? 'identifier_conflict' : null,
            $input->hasBlockingValidationIssue ? 'blocking_validation_issue' : null,
        ]));
        sort($reasons, SORT_STRING);

        $eligible = $reasons === [];
        $status = $eligible
            ? 'reappeared'
            : ($input->hasIdentifierConflict ? 'blocked' : 'review_only');

        return new SupplierOfferReappearancePreviewResult(
            supplierKey: $input->supplierKey,
            supplierSkuHash: $input->supplierSkuHash,
            previousPresenceStatus: $input->previousPresenceStatus,
            nextPresenceStatus: $status,
            evaluatedAt: $input->evaluatedAt,
            reactivationEligible: $eligible,
            wouldReactivateSupplierOffer: $eligible,
            requiresHumanReview: ! $eligible,
            reasonCodes: $reasons,
        );
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'automatic_supplier_offer_write_allowed' => false,
            'exact_supplier_sku_required' => true,
            'identifier_conflict_blocks_reactivation' => true,
            'policy_key' => self::POLICY_KEY,
            'qualified_full_snapshot_required' => true,
            'supplier_mapper_validation_required' => true,
            'valid_price_required' => true,
            'zero_price_handling' => 'review_only',
            'write_allowed' => false,
        ];
    }
}
