<?php

namespace Database\Migrations\Support;

use RuntimeException;

final class CanonicalSupplierPhaseThreeP0SchemaException extends RuntimeException
{
    public function __construct(
        public readonly string $primaryCode,
        public readonly string $invocationId,
    ) {
        parent::__construct($primaryCode);
    }
}
