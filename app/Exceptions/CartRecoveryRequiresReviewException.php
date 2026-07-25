<?php

namespace App\Exceptions;

use RuntimeException;

class CartRecoveryRequiresReviewException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The saved cart cannot be restored automatically.');
    }
}
