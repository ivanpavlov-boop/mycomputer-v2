<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentProviderIndeterminateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Резултатът от платежния опит не е потвърден. Опитайте отново със същата заявка.',
        );
    }
}
