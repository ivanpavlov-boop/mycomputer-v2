<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierHumanDecisionRegister;
use App\Data\Suppliers\Onboarding\SupplierPreviewFeedProfileDesign;

final class SupplierPreviewFeedProfileDesignValidator
{
    /** @return array<string, mixed> */
    public function validate(SupplierPreviewFeedProfileDesign $profile, SupplierHumanDecisionRegister $register): array
    {
        $errors = [];
        $knownDecisionIds = [];
        foreach ($register->decisions as $decision) {
            $knownDecisionIds[$decision->decisionId] = true;
        }

        if ($profile->supplierKey !== $register->supplierKey) {
            $errors[] = 'profile_supplier_mismatch';
        }
        if ($profile->decisionRegisterKey !== $register->key) {
            $errors[] = 'profile_decision_register_mismatch';
        }

        foreach (array_merge($profile->fieldMappings, $profile->actionMatrix) as $entry) {
            $decisionId = (string) ($entry['decision_id'] ?? '');
            if ($decisionId === '' || ! isset($knownDecisionIds[$decisionId])) {
                $errors[] = 'profile_references_unknown_decision:'.$decisionId;
            }
            foreach (['automatic_execution_allowed', 'catalog_write_allowed', 'staging_write_allowed', 'profile_persistence_allowed'] as $flag) {
                if (($entry[$flag] ?? false) === true) {
                    $errors[] = 'preview_profile_execution_flag_not_allowed:'.$flag;
                }
            }
        }

        $errors = array_values(array_unique($errors));
        sort($errors, SORT_STRING);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'read_only' => true,
            'persisted' => false,
            'executable' => false,
        ];
    }
}
