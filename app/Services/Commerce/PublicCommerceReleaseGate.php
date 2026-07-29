<?php

namespace App\Services\Commerce;

final class PublicCommerceReleaseGate
{
    public const STATE_CLOSED = 'closed';

    public const STATE_CONFIRMATION_ONLY = 'confirmation_only';

    public const STATE_OPEN = 'open';

    public const STATE_INVALID = 'invalid';

    public function state(): string
    {
        $enabled = config('commerce.public.enabled');
        $confirmationEnabled = config('commerce.public.confirmation_enabled');

        if (! is_bool($enabled) || ! is_bool($confirmationEnabled)) {
            return self::STATE_INVALID;
        }

        if ($enabled && ! $confirmationEnabled) {
            return self::STATE_INVALID;
        }

        if ($enabled) {
            return self::STATE_OPEN;
        }

        return $confirmationEnabled
            ? self::STATE_CONFIRMATION_ONLY
            : self::STATE_CLOSED;
    }

    public function canStartCheckout(): bool
    {
        return $this->state() === self::STATE_OPEN;
    }

    public function canShowConfirmation(): bool
    {
        return in_array($this->state(), [
            self::STATE_CONFIRMATION_ONLY,
            self::STATE_OPEN,
        ], true);
    }

    public function isValidConfiguration(): bool
    {
        return $this->state() !== self::STATE_INVALID;
    }
}
