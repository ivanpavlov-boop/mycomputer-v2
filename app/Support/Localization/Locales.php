<?php

namespace App\Support\Localization;

use Illuminate\Http\Request;

class Locales
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function supported(): array
    {
        return config('locales.supported', []);
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::supported());
    }

    public static function default(): string
    {
        return self::normalize(config('locales.default', 'bg'));
    }

    public static function fallback(): string
    {
        return self::normalize(config('locales.fallback', self::default()));
    }

    public static function normalize(?string $locale): string
    {
        $locale = strtolower((string) ($locale ?: self::default()));
        $locale = str_replace('_', '-', $locale);
        $locale = explode('-', $locale)[0] ?: self::default();

        return self::isSupported($locale) ? $locale : self::fallback();
    }

    public static function isSupported(?string $locale): bool
    {
        return in_array(strtolower((string) $locale), self::codes(), true);
    }

    public static function fromRequest(Request $request): string
    {
        $explicit = $request->query('locale') ?: $request->header('X-Locale');

        if (filled($explicit)) {
            return self::normalize((string) $explicit);
        }

        return self::normalize(app()->getLocale());
    }

    public static function urlPrefix(string $locale): ?string
    {
        $locale = self::normalize($locale);

        return config("locales.supported.$locale.url_prefix");
    }

    public static function localizedPath(string $path, string $locale): string
    {
        $path = '/'.ltrim($path, '/');
        $prefix = self::urlPrefix($locale);

        if (blank($prefix)) {
            return $path;
        }

        return '/'.trim($prefix, '/').$path;
    }
}
