<?php

namespace App\Contracts\Suppliers;

use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Models\SupplierFeed;

interface SupplierImportSourceDescriptorProvider
{
    public function descriptorFor(
        SupplierFeed $lockedFeed,
        CanonicalSupplierImportMapping $mapping,
    ): CanonicalSupplierSourceProfileDescriptor;
}
