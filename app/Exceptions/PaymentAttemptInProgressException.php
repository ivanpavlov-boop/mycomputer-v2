<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentAttemptInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Платежният опит се обработва.');
    }
}
