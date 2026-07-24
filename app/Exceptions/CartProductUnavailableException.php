<?php

namespace App\Exceptions;

use RuntimeException;

class CartProductUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly array $issues,
    ) {
        parent::__construct('Product is not available for purchase.');
    }

    public function details(): array
    {
        return [
            'product_id' => $this->productId,
            'issues' => $this->issues,
        ];
    }
}
