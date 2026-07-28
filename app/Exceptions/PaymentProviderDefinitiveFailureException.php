<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentProviderDefinitiveFailureException extends RuntimeException
{
    public function __construct(public readonly string $failureCode = 'provider_rejected')
    {
        parent::__construct('Платежният опит беше отхвърлен.');
    }
}
