<?php

namespace App\Services\Orders;

final readonly class CheckoutIdempotencyContext
{
    public function __construct(
        public string $keyHash,
        public string $requestHash,
        public ?string $submittedCartSession,
        public ?int $authenticatedUserId,
    ) {}
}
