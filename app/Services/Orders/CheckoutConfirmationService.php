<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutConfirmationUnavailableException;
use App\Models\CheckoutConfirmationCapability;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class CheckoutConfirmationService
{
    public const COOKIE_NAME = 'mc_checkout_confirmation';

    public const LIFETIME_MINUTES = 120;

    public const LIFETIME_SECONDS = self::LIFETIME_MINUTES * 60;

    private const TOKEN_BYTES = 32;

    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    public function issue(Order $order): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');

        $order->checkoutConfirmationCapabilities()
            ->where('expires_at', '<=', now())
            ->delete();

        CheckoutConfirmationCapability::query()->create([
            'order_id' => $order->getKey(),
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
        ]);

        return $token;
    }

    public function resolve(?string $token): CheckoutConfirmationCapability
    {
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new CheckoutConfirmationUnavailableException;
        }

        $tokenHash = $this->hash($token);
        $capability = CheckoutConfirmationCapability::query()
            ->with(['order.paymentTransactions.method'])
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())
            ->first();

        if (
            ! $capability
            || ! hash_equals($capability->token_hash, $tokenHash)
            || ! $capability->order
        ) {
            throw new CheckoutConfirmationUnavailableException;
        }

        return $capability;
    }

    public function cookie(string $token, Request $request): Cookie
    {
        return new Cookie(
            name: self::COOKIE_NAME,
            value: $token,
            expire: time() + self::LIFETIME_SECONDS,
            path: '/',
            domain: null,
            secure: $this->requiresSecureCookie($request),
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
            path: '/',
            domain: null,
            secure: $this->requiresSecureCookie($request),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function maskEmail(?string $email): string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '' || $domain === '') {
            return '***';
        }

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * @return array{
     *     currency: string,
     *     method_name: string,
     *     redirect_url: string|null,
     *     instructions: string|null
     * }
     */
    public function safePaymentPresentation(Order $order): array
    {
        $transaction = $order->paymentTransactions
            ->sortByDesc('id')
            ->first();
        $raw = is_array($transaction?->raw_response) ? $transaction->raw_response : [];
        $redirectUrl = $this->approvedRedirectUrl($raw['redirect_url'] ?? null);
        $instructions = $raw['instructions'] ?? $transaction?->method?->instructions;

        return [
            'currency' => is_string($transaction?->currency) && strlen($transaction->currency) === 3
                ? strtoupper($transaction->currency)
                : 'EUR',
            'method_name' => $transaction?->method?->name ?: $order->payment_method,
            'redirect_url' => $redirectUrl,
            'instructions' => is_string($instructions) && trim($instructions) !== ''
                ? Str::limit(trim($instructions), 2000, '')
                : null,
        ];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function requiresSecureCookie(Request $request): bool
    {
        return $request->isSecure() || app()->isProduction();
    }

    private function approvedRedirectUrl(mixed $redirectUrl): ?string
    {
        if (! is_string($redirectUrl) || filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(parse_url($redirectUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $redirectUrl
            : null;
    }
}
