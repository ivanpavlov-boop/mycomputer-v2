<?php

namespace App\Exceptions;

use App\Support\Api\ErrorResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CartMergeConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cart merge requires review.');
    }

    public function render(): JsonResponse
    {
        return ErrorResponse::make(
            'cart_merge_conflict',
            $this->getMessage(),
            409,
        );
    }
}
