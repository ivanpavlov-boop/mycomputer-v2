<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierOfferLifecycleEvaluationGate;

final class SupplierOfferLifecycleEvaluationGateFactory
{
    public function create(): SupplierOfferLifecycleEvaluationGate
    {
        return new SupplierOfferLifecycleEvaluationGate(
            decisionRegisterKey: SupplierHumanDecisionRegistry::APCOM_REGISTER_V4,
            previewProfileKey: SupplierPreviewFeedProfileDesignRegistry::APCOM_PROFILE_V4,
            evaluationAllowed: true,
        );
    }
}
