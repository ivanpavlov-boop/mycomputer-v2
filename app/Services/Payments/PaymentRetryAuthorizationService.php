<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentRetryUnavailableException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentRetryCapability;
use App\Models\User;
use Illuminate\Http\Request;

class PaymentRetryAuthorizationService
{
    public function __construct(
        private readonly PaymentRetryCapabilityService $capabilities,
    ) {}

    public function accountOwner(Order $order, User $user): PaymentRetryAuthorization
    {
        if ($order->user_id === null || (int) $order->user_id !== (int) $user->getKey()) {
            throw new PaymentRetryUnavailableException;
        }

        return PaymentRetryAuthorization::accountOwner($order, $user->getKey());
    }

    public function guest(Request $request): PaymentRetryAuthorization
    {
        $capability = $this->capabilities->resolve(
            $request->cookie(PaymentRetryCapabilityService::COOKIE_NAME),
        );

        return PaymentRetryAuthorization::guestCapability(
            $capability->order,
            $capability->getKey(),
        );
    }

    public function recheck(PaymentRetryAuthorization $authorization, Order $order): void
    {
        if ($authorization->type === PaymentAttempt::AUTH_ACCOUNT_OWNER) {
            if (
                $authorization->userId === null
                || $order->user_id === null
                || (int) $order->user_id !== (int) $authorization->userId
            ) {
                throw new PaymentRetryUnavailableException;
            }

            return;
        }

        $capability = PaymentRetryCapability::query()
            ->lockForUpdate()
            ->whereKey($authorization->capabilityId)
            ->where('order_id', $order->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $capability || $order->user_id !== null) {
            throw new PaymentRetryUnavailableException;
        }
    }
}
