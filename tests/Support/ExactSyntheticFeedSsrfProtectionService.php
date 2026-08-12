<?php

namespace Tests\Support;

use App\Services\Security\SsrfProtectionService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ExactSyntheticFeedSsrfProtectionService extends SsrfProtectionService
{
    /** @var array<int, string> */
    public array $requestedUrls = [];

    public function __construct(private readonly string $expectedUrl) {}

    public function downloadToTemporaryFile(
        string $url,
        ?string $username = null,
        ?string $password = null,
        int $maxRedirects = 3,
    ): string {
        if ($url !== $this->expectedUrl || $username !== null || $password !== null || $maxRedirects !== 3) {
            throw new RuntimeException('unexpected_synthetic_feed_request');
        }

        $this->requestedUrls[] = $url;
        $response = Http::get($url)->throw();
        $path = tempnam(sys_get_temp_dir(), 'synthetic-supplier-feed-');

        if ($path === false || file_put_contents($path, $response->body()) === false) {
            throw new RuntimeException('synthetic_feed_fixture_write_failed');
        }

        return $path;
    }
}
