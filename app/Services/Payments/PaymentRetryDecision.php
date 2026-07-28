<?php

namespace App\Services\Payments;

use App\Models\PaymentTransaction;

final readonly class PaymentRetryDecision
{
    private function __construct(
        public bool $initiateProvider,
        public ?PaymentTransaction $existingTransaction,
    ) {}

    public static function initiate(): self
    {
        return new self(true, null);
    }

    public static function existing(PaymentTransaction $transaction): self
    {
        return new self(false, $transaction);
    }
}
