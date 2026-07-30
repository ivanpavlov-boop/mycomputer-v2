<?php

namespace App\Services\Legal;

use Throwable;

final class LegalContentRegistry
{
    private const TERMS_ROUTE = '/obshti-usloviya';

    private const PRIVACY_ROUTE = '/politika-za-poveritelnost';

    private ?array $resolvedManifest = null;

    private bool $manifestResolved = false;

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        if ($this->manifestResolved) {
            return $this->resolvedManifest ?? [];
        }

        $this->manifestResolved = true;
        $path = config('legal.manifest_path');

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return $this->resolvedManifest = [];
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return $this->resolvedManifest = [];
        }

        return $this->resolvedManifest = is_array($decoded) ? $decoded : [];
    }

    public function isManifestValid(): bool
    {
        $manifest = $this->manifest();

        if (($manifest['locale'] ?? null) !== 'bg') {
            return false;
        }

        if (! in_array($manifest['status'] ?? null, ['draft', 'approved'], true)) {
            return false;
        }

        return $this->documentIsValid($manifest['terms'] ?? null, self::TERMS_ROUTE)
            && $this->documentIsValid($manifest['privacy'] ?? null, self::PRIVACY_ROUTE);
    }

    public function isDraft(): bool
    {
        return $this->isManifestValid()
            && ($this->manifest()['status'] ?? null) === 'draft';
    }

    public function isApproved(): bool
    {
        return config('legal.approved') === true
            && $this->isManifestValid()
            && ($this->manifest()['status'] ?? null) === 'approved'
            && $this->effectiveDatesPresent();
    }

    public function termsRoute(): string
    {
        return $this->documentValue('terms', 'route');
    }

    public function privacyRoute(): string
    {
        return $this->documentValue('privacy', 'route');
    }

    public function termsVersion(): string
    {
        return $this->documentValue('terms', 'version');
    }

    public function privacyVersion(): string
    {
        return $this->documentValue('privacy', 'version');
    }

    public function effectiveDatesPresent(): bool
    {
        return $this->documentValue('terms', 'effective_date') !== ''
            && $this->documentValue('privacy', 'effective_date') !== '';
    }

    private function documentIsValid(mixed $document, string $expectedRoute): bool
    {
        if (! is_array($document) || ($document['route'] ?? null) !== $expectedRoute) {
            return false;
        }

        return is_string($document['version'] ?? null)
            && trim($document['version']) !== ''
            && (
                ($document['effective_date'] ?? null) === null
                || (
                    is_string($document['effective_date'])
                    && trim($document['effective_date']) !== ''
                )
            );
    }

    private function documentValue(string $document, string $key): string
    {
        $value = $this->manifest()[$document][$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
