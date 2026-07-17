<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CatalogProductDeletionPolicyInput;
use App\Data\Suppliers\Onboarding\CatalogProductDeletionPolicyPreviewResult;

final class CatalogProductDeletionPolicy
{
    public const POLICY_KEY = 'catalog-product-deletion-policy-v1';

    public function preview(CatalogProductDeletionPolicyInput $input): CatalogProductDeletionPolicyPreviewResult
    {
        $manualReviewCandidate = $input->isDemonstrablyTestDuplicateOrErroneous
            && (! $input->hasEverBeenPublished || $input->hasExplicitDuplicateConsolidationPlan)
            && ! $input->hasOrderHistory
            && ! $input->hasSupplierHistory
            && ! $input->hasActiveSupplierOffer
            && ! $input->hasRequiredRelationalDependency
            && $input->seoRedirectReviewed
            && $input->previewAndBackupExist;

        $reasonCodes = array_values(array_filter([
            $input->hasEverBeenPublished ? 'previously_published' : null,
            $input->hasOrderHistory ? 'order_history_present' : null,
            $input->hasSupplierHistory ? 'supplier_history_present' : null,
            $input->hasActiveSupplierOffer ? 'active_supplier_offer_present' : null,
            $input->hasRequiredRelationalDependency ? 'required_relation_present' : null,
            ! $input->isDemonstrablyTestDuplicateOrErroneous ? 'not_test_duplicate_or_erroneous' : null,
            ! $input->seoRedirectReviewed ? 'seo_redirect_not_reviewed' : null,
            ! $input->previewAndBackupExist ? 'preview_or_backup_missing' : null,
        ]));
        sort($reasonCodes, SORT_STRING);

        return new CatalogProductDeletionPolicyPreviewResult(
            productReferenceHash: $input->productReferenceHash,
            manualReviewClassification: $manualReviewCandidate ? 'future_manual_review_candidate' : 'not_a_manual_review_candidate',
            reasonCodes: $reasonCodes,
            requiresSuperAdminReview: true,
        );
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'automatic_hard_delete_allowed' => false,
            'automatic_soft_delete_allowed' => false,
            'eol_alone_is_delete_reason' => false,
            'long_term_unavailability_alone_is_delete_reason' => false,
            'policy_key' => self::POLICY_KEY,
            'supplier_absence_alone_is_delete_reason' => false,
            'write_allowed' => false,
        ];
    }
}
