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
        return self::supportedLocaleFromTag(config('locales.default', 'bg'))
            ?? self::firstSupportedLocale()
            ?? 'bg';
    }

    public static function fallback(): string
    {
        return self::supportedLocaleFromTag(config('locales.fallback', self::default()))
            ?? self::default();
    }

    public static function normalize(?string $locale): string
    {
        return self::supportedLocaleFromTag($locale) ?? self::fallback();
    }

    public static function isSupported(?string $locale): bool
    {
        return in_array(strtolower(trim((string) $locale)), self::codes(), true);
    }

    public static function fromRequest(Request $request): string
    {
        return self::normalize($request->getLocale() ?: app()->getLocale());
    }

    public static function resolveApiRequest(Request $request): string
    {
        if ($request->hasHeader('X-Locale')) {
            return self::supportedLocaleFromTag($request->header('X-Locale')) ?? self::fallback();
        }

        if ($request->hasHeader('Accept-Language')) {
            foreach ($request->getLanguages() as $language) {
                $locale = self::supportedLocaleFromTag($language);

                if ($locale !== null) {
                    return $locale;
                }
            }
        }

        // Keep the existing, validated API query option as a backwards-compatible fallback.
        if ($request->query->has('locale') && self::isSupported($request->query('locale'))) {
            return strtolower(trim((string) $request->query('locale')));
        }

        return self::default();
    }

    private static function supportedLocaleFromTag(?string $locale): ?string
    {
        $locale = strtolower(trim((string) $locale));

        if (! preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/', $locale)) {
            return null;
        }

        $primaryLocale = explode('-', str_replace('_', '-', $locale))[0];

        return self::isSupported($primaryLocale) ? $primaryLocale : null;
    }

    private static function firstSupportedLocale(): ?string
    {
        $locale = array_key_first(self::supported());

        return is_string($locale) ? $locale : null;
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
