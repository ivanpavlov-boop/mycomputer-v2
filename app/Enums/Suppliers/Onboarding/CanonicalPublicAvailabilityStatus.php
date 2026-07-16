<?php

namespace App\Enums\Suppliers\Onboarding;

enum CanonicalPublicAvailabilityStatus: string
{
    case InStock = 'in_stock';
    case Limited = 'limited';
    case OnRequest = 'on_request';
    case LastUnits = 'last_units';
    case Unavailable = 'unavailable';
    case Discontinued = 'discontinued';
    case Unknown = 'unknown';
}
