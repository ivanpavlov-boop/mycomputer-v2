<?php

namespace App\Services\Orders;

use App\Models\Order;
use SensitiveParameter;

final readonly class CheckoutResult
{
    public function __construct(
        private Order $order,
        #[SensitiveParameter]
        private string $confirmationCapability,
        #[SensitiveParameter]
        private ?string $paymentRetryCapability,
        private bool $replayed,
    ) {}

    public function order(): Order
    {
        return $this->order;
    }

    public function confirmationCapability(): string
    {
        return $this->confirmationCapability;
    }

    public function replayed(): bool
    {
        return $this->replayed;
    }

    public function paymentRetryCapability(): ?string
    {
        return $this->paymentRetryCapability;
    }
}
