<?php

namespace App\Exceptions;

use RuntimeException;

class CartRecoveryConsumedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This recovery link has already been used.');
    }
}
