<?php

namespace App\Data\Suppliers\Onboarding;

enum ValidationSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case BLOCKER = 'blocker';
}
