<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutAlreadyCompletedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Тази количка вече е превърната в поръчка.');
    }
}
