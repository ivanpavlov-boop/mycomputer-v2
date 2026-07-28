<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentRetryNotAllowedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode = 'payment_retry_not_allowed',
        string $message = 'Нов платежен опит не е разрешен.',
        public readonly int $statusCode = 409,
    ) {
        parent::__construct($message);
    }
}
