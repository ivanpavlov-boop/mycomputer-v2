<?php

namespace App\Models\Concerns;

use App\Support\Localization\Locales;

trait HasLocalizedFields
{
    public function localizedField(string $field, ?string $locale = null, bool $fallbackToPrimary = true): ?string
    {
        $locale = Locales::normalize($locale);
        $translation = $this->translationFor($field, $locale);

        if (filled($translation)) {
            return $translation;
        }

        if ($locale === Locales::default()) {
            return $this->primaryFieldValue($field);
        }

        if (! $fallbackToPrimary) {
            return null;
        }

        return $this->translationFor($field, Locales::default()) ?: $this->primaryFieldValue($field);
    }

    public function hasLocalizedField(string $field, string $locale): bool
    {
        return filled($this->translationFor($field, Locales::normalize($locale)));
    }

    /**
     * @return array<string, string>
     */
    public function localizedPayload(string $field): array
    {
        $payload = [];

        foreach (Locales::codes() as $locale) {
            $payload[$locale] = $this->localizedField($field, $locale, fallbackToPrimary: $locale === Locales::default()) ?? '';
        }

        return $payload;
    }

    private function translationFor(string $field, string $locale): ?string
    {
        $translations = $this->getAttribute($field.'_translations');

        if (! is_array($translations)) {
            return null;
        }

        $value = $translations[$locale] ?? null;

        return filled($value) ? (string) $value : null;
    }

    private function primaryFieldValue(string $field): ?string
    {
        $value = $this->getAttribute($field);

        return filled($value) ? (string) $value : null;
    }
}
