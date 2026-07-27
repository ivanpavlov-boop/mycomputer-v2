<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentMethodUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Избраният начин на плащане не е наличен.');
    }
}
