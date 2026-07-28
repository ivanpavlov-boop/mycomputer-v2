<?php

namespace App\Services\Payments;

class PaymentRedirectPolicy
{
    public function approved(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (
            ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || $host === 'localhost'
            || ! in_array($host, $this->approvedHosts(), true)
            || $this->isDisallowedIp($host)
        ) {
            return null;
        }

        return $url;
    }

    /**
     * @return array<int, string>
     */
    private function approvedHosts(): array
    {
        return collect(config('payments.methods.card.approved_redirect_hosts', []))
            ->filter(fn (mixed $host): bool => is_string($host) && trim($host) !== '')
            ->map(fn (string $host): string => strtolower(trim($host)))
            ->unique()
            ->values()
            ->all();
    }

    private function isDisallowedIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
