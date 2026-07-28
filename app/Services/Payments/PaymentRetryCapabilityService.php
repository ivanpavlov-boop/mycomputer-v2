<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentRetryUnavailableException;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentRetryCapability;
use Illuminate\Http\Request;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie;

class PaymentRetryCapabilityService
{
    public const COOKIE_NAME = 'mc_payment_retry';

    public const COOKIE_PATH = '/api/v1/checkout/payment-attempts';

    public const LIFETIME_MINUTES = 60;

    public const LIFETIME_SECONDS = self::LIFETIME_MINUTES * 60;

    private const TOKEN_BYTES = 32;

    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    public function __construct(
        private readonly PaymentMethodAvailabilityService $availability,
    ) {}

    public function issue(Order $order): ?string
    {
        if (! $this->canIssue($order)) {
            return null;
        }

        PaymentRetryCapability::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $token = rtrim(
            strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'),
            '=',
        );

        PaymentRetryCapability::query()->create([
            'order_id' => $order->getKey(),
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
        ]);

        return $token;
    }

    public function resolve(#[SensitiveParameter] mixed $token): PaymentRetryCapability
    {
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new PaymentRetryUnavailableException;
        }

        PaymentRetryCapability::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $tokenHash = $this->hash($token);
        $capability = PaymentRetryCapability::query()
            ->with('order')
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (
            ! $capability
            || ! hash_equals($capability->token_hash, $tokenHash)
            || ! $capability->order
        ) {
            throw new PaymentRetryUnavailableException;
        }

        $capability->update(['last_used_at' => now()]);

        return $capability;
    }

    public function cookie(#[SensitiveParameter] string $token, Request $request): Cookie
    {
        return new Cookie(
            name: self::COOKIE_NAME,
            value: $token,
            expire: time() + self::LIFETIME_SECONDS,
            path: self::COOKIE_PATH,
            domain: null,
            secure: $request->isSecure() || app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function forgetCookie(Request $request): Cookie
    {
        return new Cookie(
            name: self::COOKIE_NAME,
            value: '',
            expire: 1,
            path: self::COOKIE_PATH,
            domain: null,
            secure: $request->isSecure() || app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function canIssue(Order $order): bool
    {
        if ($order->user_id !== null) {
            return false;
        }

        $method = PaymentMethod::query()
            ->with('provider')
            ->where('code', $order->payment_method)
            ->first();

        return $method !== null
            && $method->type === 'online'
            && $this->availability->isAvailable($method);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
