<?php

namespace App\Exceptions;

use RuntimeException;

class CardPaymentProviderUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Card payment provider is unavailable.');
    }
}
