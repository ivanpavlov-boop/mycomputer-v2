<?php

namespace App\Exceptions;

use RuntimeException;

class CartQuantityUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requestedQuantity,
        public readonly ?int $availableQuantity,
        public readonly int $maxPurchasableQuantity,
        public readonly array $issues,
    ) {
        parent::__construct('The requested quantity is not available.');
    }

    public function details(): array
    {
        return [
            'product_id' => $this->productId,
            'requested_quantity' => $this->requestedQuantity,
            'available_quantity' => $this->availableQuantity,
            'max_purchasable_quantity' => $this->maxPurchasableQuantity,
            'issues' => $this->issues,
        ];
    }
}
