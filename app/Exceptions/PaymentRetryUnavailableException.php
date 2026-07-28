<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentRetryUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Заявката за плащане не е налична.');
    }
}
