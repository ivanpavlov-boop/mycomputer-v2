<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutConfirmationUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Checkout confirmation is unavailable.');
    }
}
