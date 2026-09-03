<?php

namespace App\Exceptions;

use RuntimeException;

final class SupplierImportSourceProfileOwnerNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('source_profile_feed_owner_not_found');
    }
}
