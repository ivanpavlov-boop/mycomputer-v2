<?php

namespace App\Exceptions;

use RuntimeException;

class CartRecoveryForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cart recovery is not allowed for this account.');
    }
}
