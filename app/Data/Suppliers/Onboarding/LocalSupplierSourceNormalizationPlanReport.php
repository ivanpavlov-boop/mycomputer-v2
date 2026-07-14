<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class LocalSupplierSourceNormalizationPlanReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-local-source-normalization-plan-v1';

    /**
     * @param  array<string, mixed>  $supplier
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $sourceFingerprint
     * @param  array<string, mixed>  $expectedState
     * @param  array<string, mixed>  $observedState
     * @param  array<string, mixed>  $baselineLock
     * @param  array<string, mixed>  $activeImportCheck
     * @param  array<string, bool>  $globalSafetyFlags
     * @param  array<string, mixed>  $sourceProfile
     * @param  array<string, mixed>  $fieldCoverage
     * @param  array<string, mixed>  $fieldCompatibility
     * @param  array<string, mixed>  $identifierStrategy
     * @param  array<string, mixed>  $proposedNormalizationRules
     * @param  array<string, mixed>  $offerFieldPlan
     * @param  array<string, mixed>  $descriptiveFieldPolicy
     * @param  array<string, mixed>  $categoryMappingPolicy
     * @param  array<string, mixed>  $attributePolicy
     * @param  array<string, mixed>  $imagePolicy
     * @param  array<string, mixed>  $collisionPolicy
     * @param  array<int, string>  $unresolvedFields
     * @param  array<int, string>  $blockers
     * @param  array<int, string>  $warnings
     * @param  array<string, int>  $issueCounts
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<string, int>  $protectedCountsBefore
     * @param  array<string, int>  $protectedCountsAfter
     * @param  array<string, string>  $protectedStateFingerprintsBefore
     * @param  array<string, string>  $protectedStateFingerprintsAfter
     * @param  array<string, int>  $recordsChanged
     */
    public function __construct(
        public bool $success,
        public string $verdict,
        array $supplier,
        array $source,
        array $sourceFingerprint,
        array $expectedState,
        array $observedState,
        array $baselineLock,
        array $activeImportCheck,
        array $globalSafetyFlags,
        array $sourceProfile,
        public int $sourceRecordCount,
        public int $legacyStagingCount,
        public int $recordCountDelta,
        public float $recordCountDeltaPercentage,
        array $fieldCoverage,
        array $fieldCompatibility,
        array $identifierStrategy,
        array $proposedNormalizationRules,
        array $offerFieldPlan,
        array $descriptiveFieldPolicy,
        array $categoryMappingPolicy,
        array $attributePolicy,
        array $imagePolicy,
        array $collisionPolicy,
        array $unresolvedFields,
        array $blockers,
        array $warnings,
        array $issueCounts,
        array $issues,
        array $protectedCountsBefore,
        array $protectedCountsAfter,
        array $protectedStateFingerprintsBefore,
        array $protectedStateFingerprintsAfter,
        array $recordsChanged,
        public float $elapsedSeconds,
        public int $peakMemoryBytes,
    ) {
        $this->supplier = CanonicalOnboardingData::normalize($supplier);
        $this->source = CanonicalOnboardingData::normalize($source);
        $this->sourceFingerprint = CanonicalOnboardingData::normalize($sourceFingerprint);
        $this->expectedState = CanonicalOnboardingData::normalize($expectedState);
        $this->observedState = CanonicalOnboardingData::normalize($observedState);
        $this->baselineLock = CanonicalOnboardingData::normalize($baselineLock);
        $this->activeImportCheck = CanonicalOnboardingData::normalize($activeImportCheck);
        $this->globalSafetyFlags = CanonicalOnboardingData::normalize($globalSafetyFlags);
        $this->sourceProfile = CanonicalOnboardingData::normalize($sourceProfile);
        $this->fieldCoverage = CanonicalOnboardingData::normalize($fieldCoverage);
        $this->fieldCompatibility = CanonicalOnboardingData::normalize($fieldCompatibility);
        $this->identifierStrategy = CanonicalOnboardingData::normalize($identifierStrategy);
        $this->proposedNormalizationRules = CanonicalOnboardingData::normalize($proposedNormalizationRules);
        $this->offerFieldPlan = CanonicalOnboardingData::normalize($offerFieldPlan);
        $this->descriptiveFieldPolicy = CanonicalOnboardingData::normalize($descriptiveFieldPolicy);
        $this->categoryMappingPolicy = CanonicalOnboardingData::normalize($categoryMappingPolicy);
        $this->attributePolicy = CanonicalOnboardingData::normalize($attributePolicy);
        $this->imagePolicy = CanonicalOnboardingData::normalize($imagePolicy);
        $this->collisionPolicy = CanonicalOnboardingData::normalize($collisionPolicy);
        $this->unresolvedFields = array_values(array_unique(array_filter($unresolvedFields, 'is_string')));
        $this->blockers = array_values(array_unique(array_filter($blockers, 'is_string')));
        $this->warnings = array_values(array_unique(array_filter($warnings, 'is_string')));
        $this->issueCounts = CanonicalOnboardingData::normalize($issueCounts);
        $this->issues = CanonicalOnboardingData::normalize(array_slice($issues, 0, 20));
        $this->protectedCountsBefore = CanonicalOnboardingData::normalize($protectedCountsBefore);
        $this->protectedCountsAfter = CanonicalOnboardingData::normalize($protectedCountsAfter);
        $this->protectedStateFingerprintsBefore = CanonicalOnboardingData::normalize($protectedStateFingerprintsBefore);
        $this->protectedStateFingerprintsAfter = CanonicalOnboardingData::normalize($protectedStateFingerprintsAfter);
        $this->recordsChanged = CanonicalOnboardingData::normalize($recordsChanged);

        OnboardingValueGuard::assertSafe($this->toArray(), 'local supplier source normalization plan report');
    }

    /** @var array<string, mixed> */
    public array $supplier;

    /** @var array<string, mixed> */
    public array $source;

    /** @var array<string, mixed> */
    public array $sourceFingerprint;

    /** @var array<string, mixed> */
    public array $expectedState;

    /** @var array<string, mixed> */
    public array $observedState;

    /** @var array<string, mixed> */
    public array $baselineLock;

    /** @var array<string, mixed> */
    public array $activeImportCheck;

    /** @var array<string, bool> */
    public array $globalSafetyFlags;

    /** @var array<string, mixed> */
    public array $sourceProfile;

    /** @var array<string, mixed> */
    public array $fieldCoverage;

    /** @var array<string, mixed> */
    public array $fieldCompatibility;

    /** @var array<string, mixed> */
    public array $identifierStrategy;

    /** @var array<string, mixed> */
    public array $proposedNormalizationRules;

    /** @var array<string, mixed> */
    public array $offerFieldPlan;

    /** @var array<string, mixed> */
    public array $descriptiveFieldPolicy;

    /** @var array<string, mixed> */
    public array $categoryMappingPolicy;

    /** @var array<string, mixed> */
    public array $attributePolicy;

    /** @var array<string, mixed> */
    public array $imagePolicy;

    /** @var array<string, mixed> */
    public array $collisionPolicy;

    /** @var array<int, string> */
    public array $unresolvedFields;

    /** @var array<int, string> */
    public array $blockers;

    /** @var array<int, string> */
    public array $warnings;

    /** @var array<string, int> */
    public array $issueCounts;

    /** @var array<int, array<string, mixed>> */
    public array $issues;

    /** @var array<string, int> */
    public array $protectedCountsBefore;

    /** @var array<string, int> */
    public array $protectedCountsAfter;

    /** @var array<string, string> */
    public array $protectedStateFingerprintsBefore;

    /** @var array<string, string> */
    public array $protectedStateFingerprintsAfter;

    /** @var array<string, int> */
    public array $recordsChanged;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'local_source_normalization_plan',
            'read_only' => true,
            'success' => $this->success,
            'verdict' => $this->verdict,
            'supplier' => $this->supplier,
            'source' => $this->source,
            'source_fingerprint' => $this->sourceFingerprint,
            'expected_state' => $this->expectedState,
            'observed_state' => $this->observedState,
            'baseline_lock' => $this->baselineLock,
            'active_import_check' => $this->activeImportCheck,
            'global_safety_flags' => $this->globalSafetyFlags,
            'source_profile' => $this->sourceProfile,
            'source_record_count' => $this->sourceRecordCount,
            'legacy_staging_count' => $this->legacyStagingCount,
            'record_count_delta' => $this->recordCountDelta,
            'record_count_delta_percentage' => $this->recordCountDeltaPercentage,
            'field_coverage' => $this->fieldCoverage,
            'field_compatibility' => $this->fieldCompatibility,
            'identifier_strategy' => $this->identifierStrategy,
            'proposed_normalization_rules' => $this->proposedNormalizationRules,
            'offer_field_plan' => $this->offerFieldPlan,
            'descriptive_field_policy' => $this->descriptiveFieldPolicy,
            'category_mapping_policy' => $this->categoryMappingPolicy,
            'attribute_policy' => $this->attributePolicy,
            'image_policy' => $this->imagePolicy,
            'collision_policy' => $this->collisionPolicy,
            'unresolved_fields' => $this->unresolvedFields,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'issue_counts' => $this->issueCounts,
            'issues' => $this->issues,
            'human_review_required' => true,
            'executable_import_config_created' => false,
            'persisted_feed_profile_created' => false,
            'protected_counts_before' => $this->protectedCountsBefore,
            'protected_counts_after' => $this->protectedCountsAfter,
            'protected_state_fingerprints_before' => $this->protectedStateFingerprintsBefore,
            'protected_state_fingerprints_after' => $this->protectedStateFingerprintsAfter,
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
