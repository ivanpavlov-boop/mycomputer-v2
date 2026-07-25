<?php

namespace App\Exceptions;

use RuntimeException;

class CartPromotionChangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cart promotions changed. Please review your cart and try again.');
    }
}
