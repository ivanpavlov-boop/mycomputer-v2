<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SsrfProtectionService
{
    private const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'metadata.google.internal',
    ];

    private const BLOCKED_IPS = [
        '169.254.169.254',
        '100.100.100.200',
    ];

    public function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Unsupported or unsafe feed URL scheme.');
        }

        if (blank($host) || in_array($host, self::BLOCKED_HOSTS, true) || Str::endsWith($host, ['.local', '.localhost'])) {
            throw new RuntimeException('Unsafe feed URL host.');
        }

        foreach ($this->resolveHost($host) as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException('Feed URL resolves to a private or reserved network.');
            }
        }
    }

    public function get(string $url, ?string $username = null, ?string $password = null, int $maxRedirects = 3): string
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $this->assertAllowedUrl($currentUrl);

            $request = Http::timeout(60)->withOptions(['allow_redirects' => false]);

            if ($username && $password) {
                $request = $request->withBasicAuth($username, $password);
            }

            $response = $request->get($currentUrl)->throw();

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response->body();
            }

            $location = $response->header('Location');
            if (! $location) {
                throw new RuntimeException('Feed redirect is missing a Location header.');
            }

            $currentUrl = $this->absoluteUrl($currentUrl, $location);
        }

        throw new RuntimeException('Too many feed redirects.');
    }

    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);

        return collect($records)
            ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function isBlockedIp(string $ip): bool
    {
        if (in_array($ip, self::BLOCKED_IPS, true)) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function absoluteUrl(string $baseUrl, string $location): string
    {
        if (Str::startsWith($location, ['http://', 'https://'])) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (Str::startsWith($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }

        $path = trim(dirname($parts['path'] ?? '/'), '/');

        return "{$scheme}://{$host}{$port}/".trim($path.'/'.$location, '/');
    }
}
