<?php

namespace App\Services\Payments;

use SensitiveParameter;

final readonly class PaymentInitiationContext
{
    public function __construct(
        public ?string $instructions = null,
        #[SensitiveParameter]
        public ?string $providerIdempotencyKey = null,
        public ?string $attemptReference = null,
        public bool $isRetry = false,
    ) {}
}
