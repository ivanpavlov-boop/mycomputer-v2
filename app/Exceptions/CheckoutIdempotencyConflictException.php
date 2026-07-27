<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutIdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Опитът за поръчка вече е използван с различни данни.');
    }
}
