<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SupplierReadinessMatrixRow implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-readiness-matrix-v1';

    /** @var array<int, ValidationIssue> */
    public array $blockers;

    /** @var array<int, ValidationIssue> */
    public array $warnings;

    /** @var array<int, string> */
    public array $factsSource;

    /** @var array<string, scalar|array|null> */
    public array $evidence;

    /** @var array<string, scalar|array|null>|null */
    public ?array $stagingDiagnostics;

    /** @var array<string, scalar|array|null>|null */
    public ?array $mappingDiagnostics;

    /**
     * @param  array<int, ValidationIssue>  $blockers
     * @param  array<int, ValidationIssue>  $warnings
     * @param  array<int, string>  $factsSource
     * @param  array<string, scalar|array|null>  $evidence
     * @param  array<string, scalar|array|null>|null  $stagingDiagnostics
     * @param  array<string, scalar|array|null>|null  $mappingDiagnostics
     */
    public function __construct(
        public string $supplierKey,
        public string $supplierName,
        public ?string $supplierSlug,
        public bool $active,
        public ?bool $importEnabled,
        public ?bool $scheduleEnabled,
        public ?string $scheduleType,
        public string $sourceFormat,
        public bool $sourceConfigured,
        public ?bool $authenticationRequired,
        public ?bool $authenticationConfigured,
        public ?string $driverKey,
        public bool $driverAvailable,
        public ?string $driverContractVersion,
        public ?string $feedProfileKey,
        public ?string $feedProfileVersion,
        public bool $feedProfileAvailable,
        public bool $capabilityAuditAvailable,
        public bool $previewCapability,
        public bool $controlledStagingCapability,
        public bool $postApplyVerificationCapability,
        public bool $mappingReviewCapability,
        public bool $manualCreateCapability,
        public int $stagingRowCount,
        public int $linkedStagingRowCount,
        public int $unlinkedStagingRowCount,
        public int $categoryMappingCount,
        public int $canonicalFamilyCount,
        public ?string $lastImportAt,
        public ?string $lastPreviewAt,
        public ?string $lastVerifiedAt,
        public ReadinessStage $readinessStage,
        public int $readinessScore,
        array $blockers,
        array $warnings,
        public string $nextSafeAction,
        array $factsSource,
        array $evidence,
        public bool $requiresProductionReadOnlyAudit,
        ?array $stagingDiagnostics = null,
        ?array $mappingDiagnostics = null,
    ) {
        foreach ([
            'supplier key' => $supplierKey,
            'supplier name' => $supplierName,
            'source format' => $sourceFormat,
            'next safe action' => $nextSafeAction,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$label} is required.");
            }
        }

        if ($readinessScore < 0 || $readinessScore > 100) {
            throw new InvalidArgumentException('Readiness score must be between 0 and 100.');
        }

        foreach ([
            'staging row count' => $stagingRowCount,
            'linked staging row count' => $linkedStagingRowCount,
            'unlinked staging row count' => $unlinkedStagingRowCount,
            'category mapping count' => $categoryMappingCount,
            'canonical family count' => $canonicalFamilyCount,
        ] as $label => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("{$label} cannot be negative.");
            }
        }

        if ($stagingRowCount !== $linkedStagingRowCount + $unlinkedStagingRowCount) {
            throw new InvalidArgumentException('Staging counts must reconcile.');
        }

        $this->blockers = self::issues($blockers, ValidationSeverity::BLOCKER);
        $this->warnings = self::issues($warnings, ValidationSeverity::WARNING);
        $normalizedFactsSource = array_values(array_unique(array_filter($factsSource, 'is_string')));
        sort($normalizedFactsSource);
        $this->factsSource = $normalizedFactsSource;
        $this->evidence = CanonicalOnboardingData::normalize($evidence);
        $this->stagingDiagnostics = $stagingDiagnostics === null ? null : CanonicalOnboardingData::normalize($stagingDiagnostics);
        $this->mappingDiagnostics = $mappingDiagnostics === null ? null : CanonicalOnboardingData::normalize($mappingDiagnostics);

        OnboardingValueGuard::assertSafe($this->safeData(), 'supplier readiness matrix row');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'supplier_key' => $this->supplierKey,
            'supplier_name' => $this->supplierName,
            'supplier_slug' => $this->supplierSlug,
            'active' => $this->active,
            'import_enabled' => $this->importEnabled,
            'schedule_enabled' => $this->scheduleEnabled,
            'schedule_type' => $this->scheduleType,
            'source_format' => $this->sourceFormat,
            'source_configured' => $this->sourceConfigured,
            'authentication_required' => $this->authenticationRequired,
            'authentication_configured' => $this->authenticationConfigured,
            'driver_key' => $this->driverKey,
            'driver_available' => $this->driverAvailable,
            'driver_contract_version' => $this->driverContractVersion,
            'feed_profile_key' => $this->feedProfileKey,
            'feed_profile_version' => $this->feedProfileVersion,
            'feed_profile_available' => $this->feedProfileAvailable,
            'capability_audit_available' => $this->capabilityAuditAvailable,
            'preview_capability' => $this->previewCapability,
            'controlled_staging_capability' => $this->controlledStagingCapability,
            'post_apply_verification_capability' => $this->postApplyVerificationCapability,
            'mapping_review_capability' => $this->mappingReviewCapability,
            'manual_create_capability' => $this->manualCreateCapability,
            'staging_row_count' => $this->stagingRowCount,
            'linked_staging_row_count' => $this->linkedStagingRowCount,
            'unlinked_staging_row_count' => $this->unlinkedStagingRowCount,
            'category_mapping_count' => $this->categoryMappingCount,
            'canonical_family_count' => $this->canonicalFamilyCount,
            'last_import_at' => $this->lastImportAt,
            'last_preview_at' => $this->lastPreviewAt,
            'last_verified_at' => $this->lastVerifiedAt,
            'readiness_stage' => $this->readinessStage->value,
            'readiness_score' => $this->readinessScore,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'next_safe_action' => $this->nextSafeAction,
            'facts_source' => $this->factsSource,
            'evidence' => $this->evidence,
            'requires_production_read_only_audit' => $this->requiresProductionReadOnlyAudit,
            'staging_diagnostics' => $this->stagingDiagnostics,
            'mapping_diagnostics' => $this->mappingDiagnostics,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<int, ValidationIssue> $issues */
    private static function issues(array $issues, ValidationSeverity $expectedSeverity): array
    {
        foreach ($issues as $issue) {
            if (! $issue instanceof ValidationIssue || $issue->severity !== $expectedSeverity) {
                throw new InvalidArgumentException('Readiness issues must use the expected severity.');
            }
        }

        usort($issues, fn (ValidationIssue $left, ValidationIssue $right): int => strcmp($left->code, $right->code));

        return array_values($issues);
    }

    /** @return array<string, mixed> */
    private function safeData(): array
    {
        return [
            'supplier_key' => $this->supplierKey,
            'supplier_name' => $this->supplierName,
            'supplier_slug' => $this->supplierSlug,
            'source_format' => $this->sourceFormat,
            'driver_key' => $this->driverKey,
            'feed_profile_key' => $this->feedProfileKey,
            'feed_profile_version' => $this->feedProfileVersion,
            'next_safe_action' => $this->nextSafeAction,
            'facts_source' => $this->factsSource,
            'evidence' => $this->evidence,
            'staging_diagnostics' => $this->stagingDiagnostics,
            'mapping_diagnostics' => $this->mappingDiagnostics,
            'blocker_codes' => array_map(fn (ValidationIssue $issue): string => $issue->code, $this->blockers),
            'warning_codes' => array_map(fn (ValidationIssue $issue): string => $issue->code, $this->warnings),
        ];
    }
}
