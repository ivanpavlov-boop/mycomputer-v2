<?php

namespace App\Exceptions;

use RuntimeException;

class CartMutationConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cart changed during the request. Please try again.');
    }
}
