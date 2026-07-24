<?php

namespace App\Exceptions;

use App\Services\Cart\CartReadinessResult;
use RuntimeException;

class CartNotReadyException extends RuntimeException
{
    public function __construct(public readonly CartReadinessResult $readiness)
    {
        parent::__construct('Your cart contains unavailable items. Please review your cart and try again.');
    }

    public function details(): array
    {
        return ['readiness' => $this->readiness->details()];
    }
}
