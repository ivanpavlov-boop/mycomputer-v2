<?php

namespace App\Data\Suppliers\Onboarding;

enum ReadinessVerdict: string
{
    case READY_FOR_REVIEW = 'ready_for_review';
    case INCOMPLETE_INFORMATION = 'incomplete_information';
    case UNSAFE_CONFIGURATION = 'unsafe_configuration';
    case AUDIT_FAILED = 'audit_failed';
}
