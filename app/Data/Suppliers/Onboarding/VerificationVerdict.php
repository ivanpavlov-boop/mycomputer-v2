<?php

namespace App\Data\Suppliers\Onboarding;

enum VerificationVerdict: string
{
    case VERIFIED = 'verified';
    case SOURCE_MISMATCH = 'source_mismatch';
    case CANDIDATE_MISMATCH = 'candidate_mismatch';
    case DATABASE_MISMATCH = 'database_mismatch';
    case UNSAFE_CONFIGURATION = 'unsafe_configuration';
    case VERIFICATION_FAILED = 'verification_failed';
}
