<?php

namespace App\Enums\Suppliers\Onboarding;

enum CanonicalSupplierAvailabilityStatus: string
{
    case InStock = 'in_stock';
    case Limited = 'limited';
    case OnRequest = 'on_request';
    case OutOfStock = 'out_of_stock';
    case Unknown = 'unknown';
}
