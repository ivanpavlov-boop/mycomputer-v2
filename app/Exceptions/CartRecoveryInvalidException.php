<?php

namespace App\Exceptions;

use RuntimeException;

class CartRecoveryInvalidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Recovery link has expired or is invalid.');
    }
}
