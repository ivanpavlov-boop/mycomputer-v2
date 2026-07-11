<?php

namespace App\Services\Suppliers;

use RuntimeException;

class ControlledAsbisStagingApplyException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
