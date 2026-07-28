<?php

namespace App\Services\Payments;

final readonly class PaymentAttemptIdempotencyContext
{
    public function __construct(
        public string $keyHash,
        public string $requestHash,
        public string $providerIdempotencyKey,
    ) {}
}
