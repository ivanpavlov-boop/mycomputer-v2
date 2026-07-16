<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierFeedProfileApprovalGate;
use App\Data\Suppliers\Onboarding\SupplierHumanDecisionRegister;
use App\Data\Suppliers\Onboarding\SupplierPreviewFeedProfileDesign;
use App\Enums\Suppliers\Onboarding\SupplierHumanDecisionStatus;

final class SupplierFeedProfileApprovalGateFactory
{
    public function create(
        SupplierPreviewFeedProfileDesign $profile,
        SupplierHumanDecisionRegister $register,
    ): SupplierFeedProfileApprovalGate {
        $byStatus = [];
        $blocking = [];

        foreach ($register->decisions as $decision) {
            $byStatus[$decision->status][] = $decision->decisionId;
            if ($decision->blockingDecision) {
                $blocking[] = $decision->decisionId;
            }
        }

        foreach ($byStatus as &$ids) {
            sort($ids, SORT_STRING);
        }
        unset($ids);
        sort($blocking, SORT_STRING);

        return new SupplierFeedProfileApprovalGate(
            profileKey: $profile->key,
            decisionRegisterKey: $register->key,
            approvedDecisionIds: $byStatus[SupplierHumanDecisionStatus::Confirmed->value] ?? [],
            pendingDecisionIds: $byStatus[SupplierHumanDecisionStatus::Pending->value] ?? [],
            reviewOnlyDecisionIds: $byStatus[SupplierHumanDecisionStatus::ReviewOnly->value] ?? [],
            prohibitedActionIds: $byStatus[SupplierHumanDecisionStatus::Prohibited->value] ?? [],
            blockingDecisionIds: array_values(array_unique($blocking)),
            unresolvedPolicyReasons: [
                'mpn_not_approved',
                'missing_product_handling_pending',
                'zero_price_handling_review_only',
                'snapshot_freshness_threshold_pending',
                'cart_limit_policy_out_of_scope',
            ],
            semanticConfirmationComplete: false,
            operationalImportApproval: false,
            profilePersistenceApproval: false,
            scheduleEnablementApproval: false,
            catalogSyncApproval: false,
            gateStatus: 'blocked_pending_human_decisions',
            humanReviewRequired: true,
        );
    }
}
