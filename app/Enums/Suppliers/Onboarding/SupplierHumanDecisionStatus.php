<?php

namespace App\Enums\Suppliers\Onboarding;

enum SupplierHumanDecisionStatus: string
{
    case Confirmed = 'confirmed';
    case DiagnosticOnly = 'diagnostic_only';
    case ReviewOnly = 'review_only';
    case Pending = 'pending';
    case Prohibited = 'prohibited';
}
