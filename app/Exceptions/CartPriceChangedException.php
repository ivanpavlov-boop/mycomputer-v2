<?php

namespace App\Exceptions;

use RuntimeException;

class CartPriceChangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cart prices changed. Please review your cart and try again.');
    }
}
