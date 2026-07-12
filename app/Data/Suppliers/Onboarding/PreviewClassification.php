<?php

namespace App\Data\Suppliers\Onboarding;

enum PreviewClassification: string
{
    case READY_TO_CREATE = 'ready_to_create';
    case READY_WITH_WARNING = 'ready_with_warning';
    case MANUAL_REVIEW = 'manual_review';
    case BLOCKED = 'blocked';
    case READY_TO_UPDATE = 'ready_to_update';
}
