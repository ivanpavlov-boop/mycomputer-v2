<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierLocalSourceProfileReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-source-profile-v1';

    /**
     * @param  array<string, mixed>  $sourceFingerprint
     * @param  array<string, mixed>  $sourceMetadata
     * @param  array<string, mixed>  $parserResult
     * @param  array<string, mixed>  $recordPathAnalysis
     * @param  array<string, mixed>  $fieldInventory
     * @param  array<string, mixed>  $likelyFieldRoles
     * @param  array<string, mixed>  $feedProfileDraft
     * @param  array<int, string>  $blockers
     * @param  array<int, string>  $warnings
     * @param  array<string, int>  $issueCounts
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<string, int>  $recordsBefore
     * @param  array<string, int>  $recordsAfter
     * @param  array<string, int>  $recordsChanged
     */
    public function __construct(
        public string $mode,
        array $sourceFingerprint,
        array $sourceMetadata,
        array $parserResult,
        array $recordPathAnalysis,
        array $fieldInventory,
        array $likelyFieldRoles,
        array $feedProfileDraft,
        public string $verdict,
        array $blockers,
        array $warnings,
        array $issueCounts,
        array $issues,
        array $recordsBefore,
        array $recordsAfter,
        array $recordsChanged,
        public float $elapsedSeconds,
        public int $peakMemoryBytes,
    ) {
        $this->sourceFingerprint = CanonicalOnboardingData::normalize($sourceFingerprint);
        $this->sourceMetadata = CanonicalOnboardingData::normalize($sourceMetadata);
        $this->parserResult = CanonicalOnboardingData::normalize($parserResult);
        $this->recordPathAnalysis = CanonicalOnboardingData::normalize($recordPathAnalysis);
        $this->fieldInventory = CanonicalOnboardingData::normalize($fieldInventory);
        $this->likelyFieldRoles = CanonicalOnboardingData::normalize($likelyFieldRoles);
        $this->feedProfileDraft = CanonicalOnboardingData::normalize($feedProfileDraft);
        $this->blockers = array_values(array_unique(array_filter($blockers, 'is_string')));
        $this->warnings = array_values(array_unique(array_filter($warnings, 'is_string')));
        $this->issueCounts = CanonicalOnboardingData::normalize($issueCounts);
        $this->issues = CanonicalOnboardingData::normalize(array_slice($issues, 0, 20));
        $this->recordsBefore = CanonicalOnboardingData::normalize($recordsBefore);
        $this->recordsAfter = CanonicalOnboardingData::normalize($recordsAfter);
        $this->recordsChanged = CanonicalOnboardingData::normalize($recordsChanged);

        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier local source profile report');
    }

    /** @var array<string, mixed> */
    public array $sourceFingerprint;

    /** @var array<string, mixed> */
    public array $sourceMetadata;

    /** @var array<string, mixed> */
    public array $parserResult;

    /** @var array<string, mixed> */
    public array $recordPathAnalysis;

    /** @var array<string, mixed> */
    public array $fieldInventory;

    /** @var array<string, mixed> */
    public array $likelyFieldRoles;

    /** @var array<string, mixed> */
    public array $feedProfileDraft;

    /** @var array<int, string> */
    public array $blockers;

    /** @var array<int, string> */
    public array $warnings;

    /** @var array<string, int> */
    public array $issueCounts;

    /** @var array<int, array<string, mixed>> */
    public array $issues;

    /** @var array<string, int> */
    public array $recordsBefore;

    /** @var array<string, int> */
    public array $recordsAfter;

    /** @var array<string, int> */
    public array $recordsChanged;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $this->mode,
            'read_only' => true,
            'supplier' => $this->sourceMetadata['supplier'] ?? null,
            'source_fingerprint' => $this->sourceFingerprint,
            'source_metadata' => $this->sourceMetadata,
            'parser_result' => $this->parserResult,
            'record_path_analysis' => $this->recordPathAnalysis,
            'field_inventory' => $this->fieldInventory,
            'likely_field_roles' => $this->likelyFieldRoles,
            'feed_profile_draft' => $this->feedProfileDraft,
            'verdict' => $this->verdict,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'issue_counts' => $this->issueCounts,
            'issues' => $this->issues,
            'records_before' => $this->recordsBefore,
            'records_after' => $this->recordsAfter,
            'records_changed' => $this->recordsChanged,
            'elapsed_seconds' => $this->elapsedSeconds,
            'peak_memory_bytes' => $this->peakMemoryBytes,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
