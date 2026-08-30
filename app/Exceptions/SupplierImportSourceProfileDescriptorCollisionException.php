<?php

namespace App\Exceptions;

use RuntimeException;

final class SupplierImportSourceProfileDescriptorCollisionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('source_profile_descriptor_fingerprint_collision');
    }
}
