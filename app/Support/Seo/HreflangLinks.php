<?php

namespace App\Support\Seo;

use App\Support\Localization\Locales;

class HreflangLinks
{
    /**
     * @param  array<string, string|null>  $localizedPaths
     * @return array<int, array{locale: string, hreflang: string, url: string}>
     */
    public static function forPath(string $defaultPath, array $localizedPaths = []): array
    {
        $links = [];

        foreach (Locales::codes() as $locale) {
            $path = array_key_exists($locale, $localizedPaths)
                ? $localizedPaths[$locale]
                : $defaultPath;

            if (blank($path)) {
                continue;
            }

            $links[] = [
                'locale' => $locale,
                'hreflang' => $locale,
                'url' => url(Locales::localizedPath($path, $locale)),
            ];
        }

        $links[] = [
            'locale' => Locales::default(),
            'hreflang' => 'x-default',
            'url' => url(Locales::localizedPath($localizedPaths[Locales::default()] ?? $defaultPath, Locales::default())),
        ];

        return $links;
    }
}
