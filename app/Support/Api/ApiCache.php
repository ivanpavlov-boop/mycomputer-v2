<?php

namespace App\Support\Api;

use Illuminate\Support\Facades\Cache;

class ApiCache
{
    private const VERSION_KEY = 'api:v1:cache-version';

    public static function version(): int
    {
        return (int) Cache::rememberForever(self::VERSION_KEY, fn (): int => 1);
    }

    public static function key(string $name, array $parts = []): string
    {
        return 'api:v1:'.self::version().':'.$name.':'.md5(json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }
}
