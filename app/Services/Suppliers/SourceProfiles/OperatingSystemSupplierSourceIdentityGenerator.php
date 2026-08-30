<?php

namespace App\Services\Suppliers\SourceProfiles;

use App\Contracts\Suppliers\SupplierSourceIdentityGenerator;

final class OperatingSystemSupplierSourceIdentityGenerator implements SupplierSourceIdentityGenerator
{
    public function bytes(): string
    {
        return random_bytes(16);
    }
}
