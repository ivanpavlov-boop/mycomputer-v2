<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;

final readonly class PaymentRetryAuthorization
{
    public function __construct(
        public Order $order,
        public string $type,
        public ?int $userId = null,
        public ?int $capabilityId = null,
    ) {}

    public static function accountOwner(Order $order, int $userId): self
    {
        return new self($order, PaymentAttempt::AUTH_ACCOUNT_OWNER, $userId);
    }

    public static function guestCapability(Order $order, int $capabilityId): self
    {
        return new self(
            $order,
            PaymentAttempt::AUTH_GUEST_CAPABILITY,
            capabilityId: $capabilityId,
        );
    }
}
