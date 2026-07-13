<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierLegacyStagingAuditReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-legacy-staging-audit-v1';

    /**
     * @param  array<string, mixed>  $supplier
     * @param  array<string, mixed>  $configuration
     * @param  array<string, bool>  $globalSafetyFlags
     * @param  array<string, mixed>  $stagingInventory
     * @param  array<string, mixed>  $identifierDiagnostics
     * @param  array<string, mixed>  $linkedStateAnalysis
     * @param  array<string, mixed>  $catalogComparison
     * @param  array<string, mixed>  $catalogContentIsolation
     * @param  array<string, mixed>  $mappingState
     * @param  array<string, mixed>  $importHistory
     * @param  array<string, mixed>  $scheduleSafety
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
        array $supplier,
        array $configuration,
        array $globalSafetyFlags,
        array $stagingInventory,
        array $identifierDiagnostics,
        array $linkedStateAnalysis,
        array $catalogComparison,
        array $catalogContentIsolation,
        array $mappingState,
        array $importHistory,
        array $scheduleSafety,
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
        $this->supplier = CanonicalOnboardingData::normalize($supplier);
        $this->configuration = CanonicalOnboardingData::normalize($configuration);
        $this->globalSafetyFlags = CanonicalOnboardingData::normalize($globalSafetyFlags);
        $this->stagingInventory = CanonicalOnboardingData::normalize($stagingInventory);
        $this->identifierDiagnostics = CanonicalOnboardingData::normalize($identifierDiagnostics);
        $this->linkedStateAnalysis = CanonicalOnboardingData::normalize($linkedStateAnalysis);
        $this->catalogComparison = CanonicalOnboardingData::normalize($catalogComparison);
        $this->catalogContentIsolation = CanonicalOnboardingData::normalize($catalogContentIsolation);
        $this->mappingState = CanonicalOnboardingData::normalize($mappingState);
        $this->importHistory = CanonicalOnboardingData::normalize($importHistory);
        $this->scheduleSafety = CanonicalOnboardingData::normalize($scheduleSafety);
        $this->blockers = array_values(array_unique(array_filter($blockers, 'is_string')));
        $this->warnings = array_values(array_unique(array_filter($warnings, 'is_string')));
        $this->issueCounts = CanonicalOnboardingData::normalize($issueCounts);
        $this->issues = CanonicalOnboardingData::normalize(array_slice($issues, 0, 20));
        $this->recordsBefore = CanonicalOnboardingData::normalize($recordsBefore);
        $this->recordsAfter = CanonicalOnboardingData::normalize($recordsAfter);
        $this->recordsChanged = CanonicalOnboardingData::normalize($recordsChanged);

        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier legacy staging audit report');
    }

    /** @var array<string, mixed> */
    public array $supplier;

    /** @var array<string, mixed> */
    public array $configuration;

    /** @var array<string, bool> */
    public array $globalSafetyFlags;

    /** @var array<string, mixed> */
    public array $stagingInventory;

    /** @var array<string, mixed> */
    public array $identifierDiagnostics;

    /** @var array<string, mixed> */
    public array $linkedStateAnalysis;

    /** @var array<string, mixed> */
    public array $catalogComparison;

    /** @var array<string, mixed> */
    public array $catalogContentIsolation;

    /** @var array<string, mixed> */
    public array $mappingState;

    /** @var array<string, mixed> */
    public array $importHistory;

    /** @var array<string, mixed> */
    public array $scheduleSafety;

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
            'supplier' => $this->supplier,
            'configuration' => $this->configuration,
            'global_safety_flags' => $this->globalSafetyFlags,
            'staging_inventory' => $this->stagingInventory,
            'identifier_diagnostics' => $this->identifierDiagnostics,
            'linked_state_analysis' => $this->linkedStateAnalysis,
            'catalog_comparison' => $this->catalogComparison,
            'catalog_content_isolation' => $this->catalogContentIsolation,
            'mapping_state' => $this->mappingState,
            'import_history' => $this->importHistory,
            'schedule_safety' => $this->scheduleSafety,
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
