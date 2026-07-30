<?php

namespace App\Services\Legal;

use DateTimeImmutable;
use Throwable;

final class LegalContentRegistry
{
    private const APPROVAL_PHASE = 'Legal Content Finalization and Explicit Approval';

    private const TERMS_ROUTE = '/obshti-usloviya';

    private const PRIVACY_ROUTE = '/politika-za-poveritelnost';

    private ?array $resolvedManifest = null;

    private bool $manifestResolved = false;

    private ?array $resolvedApprovalRecord = null;

    private bool $approvalRecordResolved = false;

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        if (! $this->manifestResolved) {
            $this->manifestResolved = true;
            $this->resolvedManifest = $this->readJsonFile(config('legal.manifest_path'));
        }

        return $this->resolvedManifest ?? [];
    }

    public function isManifestValid(): bool
    {
        $manifest = $this->manifest();
        $status = $manifest['status'] ?? null;

        if (($manifest['locale'] ?? null) !== 'bg'
            || ! in_array($status, ['draft', 'approved'], true)) {
            return false;
        }

        if (! $this->documentIsValid(
            $manifest['terms'] ?? null,
            self::TERMS_ROUTE,
            'terms',
            $status,
        ) || ! $this->documentIsValid(
            $manifest['privacy'] ?? null,
            self::PRIVACY_ROUTE,
            'privacy',
            $status,
        )) {
            return false;
        }

        return $status === 'draft'
            || ($this->approvalMetadataIsValid($manifest['approval'] ?? null)
                && $this->approvalRecordMatchesManifest($manifest));
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
            && ($this->manifest()['status'] ?? null) === 'approved';
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
        return $this->validIsoDate($this->documentValue('terms', 'effective_date'))
            && $this->validIsoDate($this->documentValue('privacy', 'effective_date'));
    }

    private function documentIsValid(
        mixed $document,
        string $expectedRoute,
        string $sourceKey,
        string $status,
    ): bool {
        if (! is_array($document)
            || ($document['route'] ?? null) !== $expectedRoute
            || ! is_string($document['version'] ?? null)
            || trim($document['version']) === '') {
            return false;
        }

        if ($status === 'draft') {
            return ($document['effective_date'] ?? null) === null
                || $this->validIsoDate($document['effective_date'] ?? null);
        }

        $hash = $document['source_sha256'] ?? null;

        return $this->validIsoDate($document['effective_date'] ?? null)
            && is_string($hash)
            && preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1
            && $this->sourceHashMatches($sourceKey, $hash);
    }

    private function approvalMetadataIsValid(mixed $approval): bool
    {
        return is_array($approval)
            && ($approval['approved_by_role'] ?? null) === 'project_owner'
            && $this->validIsoDate($approval['approved_at'] ?? null)
            && ($approval['legal_counsel_review'] ?? null) === 'not_claimed';
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function approvalRecordMatchesManifest(array $manifest): bool
    {
        $record = $this->approvalRecord();
        $approval = $manifest['approval'] ?? [];

        if (($record['phase'] ?? null) !== self::APPROVAL_PHASE
            || ($record['locale'] ?? null) !== 'bg'
            || ($record['status'] ?? null) !== 'approved'
            || ($record['approved_by_role'] ?? null) !== ($approval['approved_by_role'] ?? null)
            || ($record['approved_at'] ?? null) !== ($approval['approved_at'] ?? null)
            || ($record['legal_counsel_review'] ?? null) !== ($approval['legal_counsel_review'] ?? null)
            || ! $this->validIsoDate($record['approved_at'] ?? null)
            || ! is_string($record['base_commit'] ?? null)
            || preg_match('/\A[a-f0-9]{40}\z/', $record['base_commit']) !== 1
            || ! is_string($record['notes'] ?? null)
            || trim($record['notes']) === '') {
            return false;
        }

        foreach (['terms', 'privacy'] as $document) {
            if (($record["{$document}_version"] ?? null) !== ($manifest[$document]['version'] ?? null)
                || ($record["{$document}_effective_date"] ?? null) !== ($manifest[$document]['effective_date'] ?? null)
                || ($record["{$document}_source_sha256"] ?? null) !== ($manifest[$document]['source_sha256'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalRecord(): array
    {
        if (! $this->approvalRecordResolved) {
            $this->approvalRecordResolved = true;
            $this->resolvedApprovalRecord = $this->readJsonFile(
                config('legal.approval_record_path'),
            );
        }

        return $this->resolvedApprovalRecord ?? [];
    }

    private function validIsoDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function sourceHashMatches(string $sourceKey, string $expectedHash): bool
    {
        $path = config("legal.source_pages.{$sourceKey}");

        return is_string($path)
            && $path !== ''
            && is_file($path)
            && is_readable($path)
            && hash_file('sha256', $path) === $expectedHash;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(mixed $path): array
    {
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function documentValue(string $document, string $key): string
    {
        $value = $this->manifest()[$document][$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
