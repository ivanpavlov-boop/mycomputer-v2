<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutIdempotencyKeyInvalidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Неуспешно започване на поръчката. Опитайте отново.');
    }
}
