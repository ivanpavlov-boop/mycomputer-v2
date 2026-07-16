<?php

namespace App\Enums\Suppliers\Onboarding;

enum CanonicalSupplierLifecycleStatus: string
{
    case Active = 'active';
    case Eol = 'eol';
    case Discontinued = 'discontinued';
    case Unknown = 'unknown';
}
