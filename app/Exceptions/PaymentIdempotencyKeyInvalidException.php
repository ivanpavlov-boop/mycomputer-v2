<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentIdempotencyKeyInvalidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ключът за платежния опит е невалиден.');
    }
}
