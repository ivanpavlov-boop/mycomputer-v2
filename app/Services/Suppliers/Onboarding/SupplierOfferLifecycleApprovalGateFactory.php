<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierOfferLifecycleApprovalGate;

final class SupplierOfferLifecycleApprovalGateFactory
{
    public function create(): SupplierOfferLifecycleApprovalGate
    {
        return new SupplierOfferLifecycleApprovalGate(
            decisionRegisterKey: SupplierHumanDecisionRegistry::APCOM_REGISTER_V3,
            previewProfileKey: SupplierPreviewFeedProfileDesignRegistry::APCOM_PROFILE_V3,
            unresolvedPolicyReasons: [
                'general_stock_price_freshness_pending',
                'lifecycle_apply_approval_missing',
                'mpn_not_approved',
                'operational_persistence_design_missing',
                'persistence_schema_migration_approval_missing',
                'retention_cleanup_approval_missing',
                'source_only_handling_pending',
                'storefront_visibility_implementation_missing',
                'sitemap_noindex_implementation_missing',
                'zero_price_operational_handling_review_only',
            ],
            structuralValidation: true,
            missingOfferPolicyConfirmed: true,
            catalogVisibilityPolicyConfirmed: true,
            technicalRetentionPolicyConfirmed: true,
            semanticConfirmationComplete: false,
            operationalImportApproval: false,
            profilePersistenceApproval: false,
            offerLifecycleWriteApproval: false,
            productVisibilityWriteApproval: false,
            scheduleEnablementApproval: false,
            catalogSyncApproval: false,
            gateStatus: 'blocked_pending_implementation_approvals',
            humanReviewRequired: true,
        );
    }
}
