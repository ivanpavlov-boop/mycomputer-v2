<?php

namespace App\Exceptions;

use RuntimeException;

class CartGiftLineImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Automatic gift items cannot be changed directly.');
    }
}
