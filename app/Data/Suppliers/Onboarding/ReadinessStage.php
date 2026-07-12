<?php

namespace App\Data\Suppliers\Onboarding;

enum ReadinessStage: string
{
    case UNKNOWN = 'unknown';
    case DISABLED = 'disabled';
    case SOURCE_NOT_CONFIGURED = 'source_not_configured';
    case SOURCE_PROFILE_REQUIRED = 'source_profile_required';
    case DRIVER_REQUIRED = 'driver_required';
    case PREVIEW_CONTRACT_READY = 'preview_contract_ready';
    case PREVIEW_READY = 'preview_ready';
    case STAGING_APPLY_CONTRACT_READY = 'staging_apply_contract_ready';
    case STAGING_PRESENT_UNVERIFIED = 'staging_present_unverified';
    case STAGING_VERIFIED = 'staging_verified';
    case MAPPING_REVIEW_REQUIRED = 'mapping_review_required';
    case MANUAL_CREATE_CANDIDATE = 'manual_create_candidate';
    case BLOCKED = 'blocked';
}
