<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentIdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ключът за платежния опит вече е използван за друга заявка.');
    }
}
