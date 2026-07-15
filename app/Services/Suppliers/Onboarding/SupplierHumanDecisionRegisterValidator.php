<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierHumanDecisionRegister;
use App\Enums\Suppliers\Onboarding\SupplierHumanDecisionStatus;

final class SupplierHumanDecisionRegisterValidator
{
    /** @var array<int, string> */
    private const REQUIRED_DECISION_IDS = [
        'APCOM-ID-001',
        'APCOM-SOURCE-001',
        'APCOM-ID-002',
        'APCOM-LIFECYCLE-001',
        'APCOM-STOCK-001',
        'APCOM-QUANTITY-001',
        'APCOM-AVAILABILITY-001',
        'APCOM-MPN-001',
        'APCOM-PRICE-001',
        'APCOM-CURRENCY-001',
        'APCOM-VAT-001',
        'APCOM-GREEN-TAX-001',
        'APCOM-SOURCE-ONLY-001',
        'APCOM-STAGING-ONLY-001',
        'APCOM-LINKED-STAGING-ONLY-001',
        'APCOM-EOL-REVIEW-001',
        'APCOM-ZERO-PRICE-001',
        'APCOM-PROHIBIT-AUTO-IMPORT-001',
        'APCOM-PROHIBIT-SCHEDULE-001',
        'APCOM-PROHIBIT-SYNC-ALL-001',
        'APCOM-PROHIBIT-AUTO-SYNC-001',
        'APCOM-PROHIBIT-UPDATE-SYNC-001',
        'APCOM-PROHIBIT-IMAGE-IMPORT-001',
        'APCOM-PROHIBIT-CONTENT-OVERWRITE-001',
    ];

    /** @return array<string, mixed> */
    public function validate(SupplierHumanDecisionRegister $register): array
    {
        $errors = [];
        $seen = [];
        $statusCounts = [];

        foreach ($register->decisions as $decision) {
            if (isset($seen[$decision->decisionId])) {
                $errors[] = 'duplicate_decision_id:'.$decision->decisionId;
            }
            $seen[$decision->decisionId] = true;

            $status = SupplierHumanDecisionStatus::tryFrom($decision->status);
            if ($status === null) {
                $errors[] = 'unknown_decision_status:'.$decision->decisionId;

                continue;
            }
            $statusCounts[$status->value] = ($statusCounts[$status->value] ?? 0) + 1;

            if ($status === SupplierHumanDecisionStatus::Confirmed && trim($decision->rationale) === '') {
                $errors[] = 'confirmed_decision_requires_rationale:'.$decision->decisionId;
            }
            if ($decision->decisionId === 'APCOM-SOURCE-001' && ! str_contains(strtolower((string) $decision->evidenceRequirement), 'sha256')) {
                $errors[] = 'source_decision_requires_pinned_sha256';
            }

            $hasExecutionPermission = $decision->automaticExecutionAllowed
                || $decision->catalogWriteAllowed
                || $decision->stagingWriteAllowed
                || $decision->profilePersistenceAllowed;

            if (in_array($status, [SupplierHumanDecisionStatus::Pending, SupplierHumanDecisionStatus::DiagnosticOnly, SupplierHumanDecisionStatus::ReviewOnly], true) && $hasExecutionPermission) {
                $errors[] = 'non_confirmed_decision_cannot_be_executable:'.$decision->decisionId;
            }
            if ($status === SupplierHumanDecisionStatus::Prohibited && $hasExecutionPermission) {
                $errors[] = 'prohibited_decision_cannot_be_allowed:'.$decision->decisionId;
            }
            if ($decision->catalogWriteAllowed) {
                $errors[] = 'catalog_write_not_allowed:'.$decision->decisionId;
            }
            if ($decision->stagingWriteAllowed) {
                $errors[] = 'staging_write_not_allowed:'.$decision->decisionId;
            }
            if ($decision->profilePersistenceAllowed) {
                $errors[] = 'profile_persistence_not_allowed:'.$decision->decisionId;
            }
        }

        foreach (self::REQUIRED_DECISION_IDS as $required) {
            if (! isset($seen[$required])) {
                $errors[] = 'required_decision_missing:'.$required;
            }
        }

        sort($errors, SORT_STRING);
        ksort($statusCounts);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'status_counts' => $statusCounts,
            'required_decision_ids' => self::REQUIRED_DECISION_IDS,
            'automatic_execution_allowed' => false,
            'catalog_write_allowed' => false,
            'staging_write_allowed' => false,
            'profile_persistence_allowed' => false,
        ];
    }
}
