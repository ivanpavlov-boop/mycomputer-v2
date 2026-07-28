<?php

namespace App\Services\Payments;

use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;

final readonly class PaymentAttemptResult
{
    public function __construct(
        public PaymentAttempt $attempt,
        public PaymentTransaction $transaction,
        public bool $replayed,
    ) {}
}
