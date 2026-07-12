<?php

namespace App\Services\Suppliers\Onboarding;

use App\Contracts\Suppliers\Onboarding\SupplierFeedDriverInterface;
use App\Data\Suppliers\Onboarding\ReadinessStage;
use App\Data\Suppliers\Onboarding\ReadinessVerdict;
use App\Data\Suppliers\Onboarding\SupplierFeedProfile;
use App\Data\Suppliers\Onboarding\SupplierReadinessMatrixReport;
use App\Data\Suppliers\Onboarding\SupplierReadinessMatrixRow;
use App\Data\Suppliers\Onboarding\ValidationIssue;
use App\Data\Suppliers\Onboarding\ValidationSeverity;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use App\Services\Suppliers\AsbisApplyReadinessAuditService;
use App\Services\Suppliers\AsbisCandidateFingerprintService;
use App\Services\Suppliers\AsbisDualFeedPreviewService;
use App\Services\Suppliers\AsbisPostApplyVerificationService;
use App\Services\Suppliers\ControlledAsbisDualFeedStagingImportService;
use App\Services\Suppliers\SupplierImportCapabilityAuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SupplierReadinessMatrixService
{
    private const PROTECTED_TABLES = [
        'suppliers',
        'supplier_products',
        'products',
        'categories',
        'supplier_category_mappings',
        'canonical_product_families',
        'category_product_attributes',
        'product_attributes',
        'attribute_values',
        'product_attribute_values',
        'catalog_sync_batches',
        'catalog_sync_logs',
    ];

    /** @var array<string, int> */
    private const STAGE_ORDER = [
        'blocked' => 1,
        'disabled' => 2,
        'source_not_configured' => 3,
        'driver_required' => 4,
        'source_profile_required' => 5,
        'preview_contract_ready' => 6,
        'preview_ready' => 7,
        'staging_apply_contract_ready' => 8,
        'staging_present_unverified' => 9,
        'mapping_review_required' => 10,
        'staging_verified' => 11,
        'manual_create_candidate' => 12,
        'unknown' => 13,
    ];

    public function __construct(private readonly SupplierImportCapabilityAuditService $capabilities) {}

    /**
     * Builds a read-only readiness view from local configuration and database metadata.
     *
     * @param  array<string, mixed>  $options
     */
    public function audit(array $options = []): SupplierReadinessMatrixReport
    {
        $startedAt = microtime(true);
        $before = $this->protectedCounts();
        $sampleLimit = $this->boundedLimit($options['sample_limit'] ?? 20);
        $issueSampleLimit = $this->boundedLimit($options['issue_sample_limit'] ?? 20);
        $activeOnly = (bool) ($options['active_only'] ?? false);
        $includeStagingCounts = (bool) ($options['include_staging_counts'] ?? false);
        $includeMappingCounts = (bool) ($options['include_mapping_counts'] ?? false);

        $capabilityAudit = $this->capabilities->audit(
            supplier: $this->optionalString($options['supplier'] ?? null),
            limit: 5000,
            includeDisabled: true,
        );
        $capabilityRows = collect($capabilityAudit['suppliers'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->when($activeOnly, fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => (bool) ($row['is_active'] ?? false)))
            ->values();
        $supplierIds = $capabilityRows
            ->pluck('supplier_id')
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $staging = $this->stagingSummaries($supplierIds, $includeStagingCounts, $sampleLimit);
        $mappings = $this->mappingSummaries($supplierIds, $includeMappingCounts);
        $globalSafetyFlags = $this->globalSafetyFlags();
        $globalUnsafe = $globalSafetyFlags['catalog_sync_update_enabled']
            || $globalSafetyFlags['catalog_sync_sync_all_enabled']
            || $globalSafetyFlags['catalog_sync_auto_enabled'];

        $rows = $capabilityRows
            ->map(fn (array $row): SupplierReadinessMatrixRow => $this->matrixRow(
                $row,
                $staging[(int) ($row['supplier_id'] ?? 0)] ?? $this->emptyStagingSummary(),
                $mappings[(int) ($row['supplier_id'] ?? 0)] ?? $this->emptyMappingSummary(),
                $globalUnsafe,
            ));
        $rows = $this->sortRows(
            $rows,
            (string) ($options['sort'] ?? 'readiness'),
            (string) ($options['direction'] ?? 'asc'),
        );

        $after = $this->protectedCounts();
        $recordsChanged = $this->recordsChanged($before, $after);
        $issues = $this->reportIssues($rows, $globalUnsafe, $recordsChanged, $issueSampleLimit);
        $verdict = $this->verdict($rows, $globalUnsafe, $recordsChanged);

        return new SupplierReadinessMatrixReport(
            generatedAt: now()->toISOString(),
            supplierCount: $rows->count(),
            activeSupplierCount: $rows->where('active', true)->count(),
            disabledSupplierCount: $rows->filter(fn (SupplierReadinessMatrixRow $row): bool => ! $row->active || $row->importEnabled === false)->count(),
            importEnabledCount: $rows->where('importEnabled', true)->count(),
            scheduleEnabledCount: $rows->where('scheduleEnabled', true)->count(),
            sourceConfiguredCount: $rows->where('sourceConfigured', true)->count(),
            authenticationConfiguredCount: $rows->where('authenticationConfigured', true)->count(),
            driverAvailableCount: $rows->where('driverAvailable', true)->count(),
            profileAvailableCount: $rows->where('feedProfileAvailable', true)->count(),
            previewCapableCount: $rows->where('previewCapability', true)->count(),
            controlledStagingCapableCount: $rows->where('controlledStagingCapability', true)->count(),
            postApplyVerificationCapableCount: $rows->where('postApplyVerificationCapability', true)->count(),
            suppliersWithStagingCount: $rows->filter(fn (SupplierReadinessMatrixRow $row): bool => $row->stagingRowCount > 0)->count(),
            suppliersWithLinkedProductsCount: $rows->filter(fn (SupplierReadinessMatrixRow $row): bool => $row->linkedStagingRowCount > 0)->count(),
            totalStagingRows: $rows->sum('stagingRowCount'),
            totalLinkedStagingRows: $rows->sum('linkedStagingRowCount'),
            globalSafetyFlags: $globalSafetyFlags,
            readinessStageCounts: $this->stageCounts($rows),
            blockerCounts: $this->issueCounts($rows, 'blockers'),
            warningCounts: $this->issueCounts($rows, 'warnings'),
            suppliers: $rows->all(),
            recordsChanged: $recordsChanged,
            issues: $issues,
            elapsedSeconds: round(microtime(true) - $startedAt, 6),
            peakMemoryBytes: memory_get_peak_usage(true),
            matrixVerdict: $verdict,
        );
    }

    /**
     * @param  array<string, mixed>  $capability
     * @param  array<string, mixed>  $staging
     * @param  array<string, mixed>  $mapping
     */
    private function matrixRow(array $capability, array $staging, array $mapping, bool $globalUnsafe): SupplierReadinessMatrixRow
    {
        $supplierKey = $this->supplierKey($capability);
        $sourceConfigured = (bool) ($capability['feed_configured'] ?? false);
        $sourceFormat = $this->sourceFormat($capability['feed_type'] ?? null);
        $active = (bool) ($capability['is_active'] ?? false);
        $importEnabled = array_key_exists('import_enabled', $capability) && $capability['import_enabled'] !== null
            ? (bool) $capability['import_enabled']
            : null;
        $scheduleEnabled = array_key_exists('schedule_enabled', $capability) && $capability['schedule_enabled'] !== null
            ? (bool) $capability['schedule_enabled']
            : null;
        $auth = is_array($capability['auth'] ?? null) ? $capability['auth'] : [];
        $authMarkersPresent = in_array(true, $auth, true);
        $authenticationRequired = $sourceConfigured && $authMarkersPresent ? true : null;
        $authenticationConfigured = $authenticationRequired === true
            ? (bool) ($capability['auth_configured'] ?? false)
            : null;
        $verifiedStagingEvidence = (int) $staging['verified_staging_evidence_count'] > 0;
        $asbisRuntimeAvailable = $verifiedStagingEvidence && $this->asbisRuntimeAvailable();
        $legacyDriverAvailable = $sourceConfigured && ($capability['driver_status'] ?? null) === 'configured';
        $driverAvailable = $asbisRuntimeAvailable || $legacyDriverAvailable;
        $driverKey = $asbisRuntimeAvailable
            ? 'asbis-dual-feed-v2'
            : $this->legacyDriverKey($sourceFormat, $legacyDriverAvailable);
        $feedProfileAvailable = $asbisRuntimeAvailable;
        $previewCapability = $asbisRuntimeAvailable && class_exists(AsbisDualFeedPreviewService::class);
        $controlledStagingCapability = $asbisRuntimeAvailable && class_exists(ControlledAsbisDualFeedStagingImportService::class);
        $postApplyVerificationCapability = $asbisRuntimeAvailable && class_exists(AsbisPostApplyVerificationService::class);
        $mappingReviewCapability = $sourceConfigured && Schema::hasTable('supplier_category_mappings');
        $manualCreateCapability = $verifiedStagingEvidence
            && (int) $staging['unlinked_staging_row_count'] > 0
            && (bool) config('catalog_sync.create_enabled', true);
        $blockers = [];
        $warnings = [];

        if (! $active) {
            $blockers[] = $this->issue('supplier_disabled', ValidationSeverity::BLOCKER);
        }

        if ($importEnabled === false) {
            $blockers[] = $this->issue('import_disabled', ValidationSeverity::BLOCKER);
        }

        if ($active && $importEnabled !== false && ! $sourceConfigured) {
            $blockers[] = $this->issue('source_not_configured', ValidationSeverity::BLOCKER);
        }

        if ($sourceConfigured && $sourceFormat === 'unknown') {
            $blockers[] = $this->issue('source_format_unknown', ValidationSeverity::BLOCKER);
        }

        if ($sourceConfigured && $authenticationRequired === null) {
            $warnings[] = $this->issue('authentication_unknown', ValidationSeverity::WARNING);
        }

        if ($authenticationRequired === true && $authenticationConfigured !== true) {
            $blockers[] = $this->issue('authentication_missing', ValidationSeverity::BLOCKER);
        }

        if ($sourceConfigured && ! $driverAvailable) {
            $blockers[] = $this->issue('driver_missing', ValidationSeverity::BLOCKER);
        }

        if ($driverAvailable && ! $feedProfileAvailable) {
            $blockers[] = $this->issue('feed_profile_missing', ValidationSeverity::BLOCKER);
        }

        if ($feedProfileAvailable && ! $previewCapability) {
            $blockers[] = $this->issue('preview_capability_missing', ValidationSeverity::BLOCKER);
        }

        if ($previewCapability && ! $controlledStagingCapability) {
            $blockers[] = $this->issue('controlled_staging_capability_missing', ValidationSeverity::BLOCKER);
        }

        if ($controlledStagingCapability && ! $postApplyVerificationCapability) {
            $blockers[] = $this->issue('post_apply_verification_missing', ValidationSeverity::BLOCKER);
        }

        if ($scheduleEnabled === true && ! $verifiedStagingEvidence) {
            $blockers[] = $this->issue('schedule_enabled_too_early', ValidationSeverity::BLOCKER);
        }

        if ((int) $staging['linked_staging_row_count'] > 0) {
            $blockers[] = $this->issue('linked_staging_before_approval', ValidationSeverity::BLOCKER);
        }

        if ((int) $staging['staging_row_count'] > 0 && ! $verifiedStagingEvidence) {
            $warnings[] = $this->issue('staging_present_without_verification', ValidationSeverity::WARNING);
        }

        $requiresProductionReadOnlyAudit = ! $verifiedStagingEvidence;

        if ($requiresProductionReadOnlyAudit) {
            $warnings[] = $this->issue('production_fact_unknown', ValidationSeverity::WARNING);
        }

        if ($globalUnsafe) {
            $blockers[] = $this->issue('unsafe_catalog_sync_configuration', ValidationSeverity::BLOCKER);
        }

        $stage = $this->stage(
            active: $active,
            importEnabled: $importEnabled,
            sourceConfigured: $sourceConfigured,
            driverAvailable: $driverAvailable,
            feedProfileAvailable: $feedProfileAvailable,
            previewCapability: $previewCapability,
            controlledStagingCapability: $controlledStagingCapability,
            stagingCount: (int) $staging['staging_row_count'],
            verifiedStagingEvidence: $verifiedStagingEvidence,
            pendingMappingCount: (int) $mapping['pending_mapping_count'],
            linkedStagingCount: (int) $staging['linked_staging_row_count'],
            scheduleEnabled: $scheduleEnabled,
            globalUnsafe: $globalUnsafe,
        );

        return new SupplierReadinessMatrixRow(
            supplierKey: $supplierKey,
            supplierName: (string) ($capability['supplier_name'] ?? $supplierKey),
            supplierSlug: $this->supplierSlug($capability),
            active: $active,
            importEnabled: $importEnabled,
            scheduleEnabled: $scheduleEnabled,
            scheduleType: $this->optionalString($capability['schedule_type'] ?? null),
            sourceFormat: $sourceFormat,
            sourceConfigured: $sourceConfigured,
            authenticationRequired: $authenticationRequired,
            authenticationConfigured: $authenticationConfigured,
            driverKey: $driverKey,
            driverAvailable: $driverAvailable,
            driverContractVersion: interface_exists(SupplierFeedDriverInterface::class)
                ? SupplierFeedDriverInterface::CONTRACT_VERSION
                : null,
            feedProfileKey: $feedProfileAvailable ? 'asbis-dual-feed-v2' : null,
            feedProfileVersion: $feedProfileAvailable ? AsbisCandidateFingerprintService::SCHEMA_VERSION : null,
            feedProfileAvailable: $feedProfileAvailable,
            capabilityAuditAvailable: $sourceConfigured && class_exists(SupplierImportCapabilityAuditService::class),
            previewCapability: $previewCapability,
            controlledStagingCapability: $controlledStagingCapability,
            postApplyVerificationCapability: $postApplyVerificationCapability,
            mappingReviewCapability: $mappingReviewCapability,
            manualCreateCapability: $manualCreateCapability,
            stagingRowCount: (int) $staging['staging_row_count'],
            linkedStagingRowCount: (int) $staging['linked_staging_row_count'],
            unlinkedStagingRowCount: (int) $staging['unlinked_staging_row_count'],
            categoryMappingCount: (int) $mapping['category_mapping_count'],
            canonicalFamilyCount: (int) $mapping['canonical_family_count'],
            lastImportAt: $this->optionalString($capability['last_import_at'] ?? null),
            lastPreviewAt: null,
            lastVerifiedAt: null,
            readinessStage: $stage,
            readinessScore: $this->score(
                active: $active,
                importEnabled: $importEnabled,
                sourceFormat: $sourceFormat,
                sourceConfigured: $sourceConfigured,
                authenticationRequired: $authenticationRequired,
                authenticationConfigured: $authenticationConfigured,
                driverAvailable: $driverAvailable,
                feedProfileAvailable: $feedProfileAvailable,
                previewCapability: $previewCapability,
                controlledStagingCapability: $controlledStagingCapability,
                postApplyVerificationCapability: $postApplyVerificationCapability,
                verifiedStagingEvidence: $verifiedStagingEvidence,
            ),
            blockers: $blockers,
            warnings: $warnings,
            nextSafeAction: $this->nextSafeAction($stage),
            factsSource: [
                'database_metadata',
                'existing_supplier_capability_audit',
                'onboarding_contracts',
                'staging_provenance_metadata',
            ],
            evidence: [
                'driver_contract_available' => interface_exists(SupplierFeedDriverInterface::class),
                'driver_production_wired' => $asbisRuntimeAvailable,
                'profile_contract_available' => class_exists(SupplierFeedProfile::class),
                'profile_production_wired' => $feedProfileAvailable,
                'legacy_driver_configured' => $legacyDriverAvailable,
                'verified_staging_evidence_count' => (int) $staging['verified_staging_evidence_count'],
                'mapping_review_table_available' => Schema::hasTable('supplier_category_mappings'),
            ],
            requiresProductionReadOnlyAudit: $requiresProductionReadOnlyAudit,
            stagingDiagnostics: $staging['diagnostics'],
            mappingDiagnostics: $mapping['diagnostics'],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function stagingSummaries(array $supplierIds, bool $includeDiagnostics, int $sampleLimit): array
    {
        if ($supplierIds === [] || ! Schema::hasTable('supplier_products')) {
            return [];
        }

        $columns = collect([
            'supplier_id',
            'product_id',
            'supplier_sku',
            'status',
            'raw_data',
        ])->filter(fn (string $column): bool => Schema::hasColumn('supplier_products', $column))->all();
        $productsBySupplier = SupplierProduct::query()
            ->whereIn('supplier_id', $supplierIds)
            ->select($columns)
            ->orderBy('supplier_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SupplierProduct $product): int => (int) $product->supplier_id);
        $summaries = [];

        foreach ($supplierIds as $supplierId) {
            $products = $productsBySupplier->get($supplierId, collect());
            $linkedCount = $products->filter(fn (SupplierProduct $product): bool => $product->product_id !== null)->count();
            $verifiedEvidenceCount = $products->filter(fn (SupplierProduct $product): bool => $this->hasVerifiedStagingEvidence($product))->count();
            $diagnostics = null;

            if ($includeDiagnostics) {
                $statusCounts = $products
                    ->groupBy(fn (SupplierProduct $product): string => $this->normalizedValue($product->status) ?? 'unknown')
                    ->map(fn (Collection $rows): int => $rows->count())
                    ->sortKeys()
                    ->all();
                $normalizedSkus = $products
                    ->map(fn (SupplierProduct $product): ?string => $this->normalizedValue($product->supplier_sku))
                    ->filter()
                    ->values();
                $diagnostics = [
                    'status_counts' => $statusCounts,
                    'duplicate_supplier_sku_group_count' => $normalizedSkus
                        ->countBy()
                        ->filter(fn (int $count): bool => $count > 1)
                        ->count(),
                    'blank_supplier_sku_count' => $products->count() - $normalizedSkus->count(),
                    'supplier_sku_sha256_samples' => $normalizedSkus
                        ->unique()
                        ->sort()
                        ->take($sampleLimit)
                        ->map(fn (string $sku): string => hash('sha256', $sku))
                        ->values()
                        ->all(),
                ];
            }

            $summaries[$supplierId] = [
                'staging_row_count' => $products->count(),
                'linked_staging_row_count' => $linkedCount,
                'unlinked_staging_row_count' => $products->count() - $linkedCount,
                'verified_staging_evidence_count' => $verifiedEvidenceCount,
                'diagnostics' => $diagnostics,
            ];
        }

        return $summaries;
    }

    /** @return array<int, array<string, mixed>> */
    private function mappingSummaries(array $supplierIds, bool $includeDiagnostics): array
    {
        if ($supplierIds === [] || ! Schema::hasTable('supplier_category_mappings')) {
            return [];
        }

        $mappingsBySupplier = SupplierCategoryMapping::query()
            ->whereIn('supplier_id', $supplierIds)
            ->get(['supplier_id', 'status', 'canonical_product_family_id'])
            ->groupBy(fn (SupplierCategoryMapping $mapping): int => (int) $mapping->supplier_id);
        $summaries = [];

        foreach ($supplierIds as $supplierId) {
            $mappings = $mappingsBySupplier->get($supplierId, collect());
            $statusCounts = $mappings
                ->groupBy(fn (SupplierCategoryMapping $mapping): string => $this->normalizedValue($mapping->status) ?? 'unknown')
                ->map(fn (Collection $rows): int => $rows->count())
                ->sortKeys()
                ->all();
            $summaries[$supplierId] = [
                'category_mapping_count' => $mappings->count(),
                'canonical_family_count' => $mappings
                    ->pluck('canonical_product_family_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'pending_mapping_count' => (int) ($statusCounts[SupplierCategoryMapping::STATUS_PENDING_REVIEW] ?? 0),
                'diagnostics' => $includeDiagnostics ? [
                    'status_counts' => $statusCounts,
                    'approved_mapping_count' => (int) ($statusCounts[SupplierCategoryMapping::STATUS_APPROVED] ?? 0),
                    'pending_mapping_count' => (int) ($statusCounts[SupplierCategoryMapping::STATUS_PENDING_REVIEW] ?? 0),
                ] : null,
            ];
        }

        return $summaries;
    }

    /** @return array<string, mixed> */
    private function emptyStagingSummary(): array
    {
        return [
            'staging_row_count' => 0,
            'linked_staging_row_count' => 0,
            'unlinked_staging_row_count' => 0,
            'verified_staging_evidence_count' => 0,
            'diagnostics' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyMappingSummary(): array
    {
        return [
            'category_mapping_count' => 0,
            'canonical_family_count' => 0,
            'pending_mapping_count' => 0,
            'diagnostics' => null,
        ];
    }

    private function hasVerifiedStagingEvidence(SupplierProduct $product): bool
    {
        $raw = is_array($product->raw_data) ? $product->raw_data : [];

        return ($raw['source'] ?? null) === 'asbis_dual_feed'
            && ($raw['candidate_payload_schema_version'] ?? null) === AsbisCandidateFingerprintService::SCHEMA_VERSION
            && $this->isSha256($raw['product_list_sha256'] ?? null)
            && $this->isSha256($raw['price_avail_sha256'] ?? null);
    }

    private function asbisRuntimeAvailable(): bool
    {
        return class_exists(AsbisApplyReadinessAuditService::class)
            && class_exists(AsbisDualFeedPreviewService::class)
            && class_exists(ControlledAsbisDualFeedStagingImportService::class)
            && class_exists(AsbisPostApplyVerificationService::class);
    }

    private function stage(
        bool $active,
        ?bool $importEnabled,
        bool $sourceConfigured,
        bool $driverAvailable,
        bool $feedProfileAvailable,
        bool $previewCapability,
        bool $controlledStagingCapability,
        int $stagingCount,
        bool $verifiedStagingEvidence,
        int $pendingMappingCount,
        int $linkedStagingCount,
        ?bool $scheduleEnabled,
        bool $globalUnsafe,
    ): ReadinessStage {
        if ($globalUnsafe || $linkedStagingCount > 0 || ($scheduleEnabled === true && ! $verifiedStagingEvidence)) {
            return ReadinessStage::BLOCKED;
        }

        if (! $active || $importEnabled === false) {
            return ReadinessStage::DISABLED;
        }

        if (! $sourceConfigured) {
            return ReadinessStage::SOURCE_NOT_CONFIGURED;
        }

        if (! $driverAvailable) {
            return ReadinessStage::DRIVER_REQUIRED;
        }

        if (! $feedProfileAvailable) {
            return ReadinessStage::SOURCE_PROFILE_REQUIRED;
        }

        if (! $previewCapability) {
            return ReadinessStage::PREVIEW_CONTRACT_READY;
        }

        if (! $controlledStagingCapability) {
            return ReadinessStage::PREVIEW_READY;
        }

        if ($stagingCount === 0) {
            return ReadinessStage::STAGING_APPLY_CONTRACT_READY;
        }

        if (! $verifiedStagingEvidence) {
            return ReadinessStage::STAGING_PRESENT_UNVERIFIED;
        }

        if ($pendingMappingCount > 0) {
            return ReadinessStage::MAPPING_REVIEW_REQUIRED;
        }

        return ReadinessStage::STAGING_VERIFIED;
    }

    private function score(
        bool $active,
        ?bool $importEnabled,
        string $sourceFormat,
        bool $sourceConfigured,
        ?bool $authenticationRequired,
        ?bool $authenticationConfigured,
        bool $driverAvailable,
        bool $feedProfileAvailable,
        bool $previewCapability,
        bool $controlledStagingCapability,
        bool $postApplyVerificationCapability,
        bool $verifiedStagingEvidence,
    ): int {
        $score = 0;
        $score += $active ? 10 : 0;
        $score += $importEnabled === true ? 10 : 0;
        $score += $sourceFormat !== 'unknown' ? 5 : 0;
        $score += $sourceConfigured ? 10 : 0;
        $score += $authenticationRequired === true && $authenticationConfigured === true ? 5 : 0;
        $score += $driverAvailable ? 15 : 0;
        $score += $feedProfileAvailable ? 15 : 0;
        $score += $previewCapability ? 10 : 0;
        $score += $controlledStagingCapability ? 10 : 0;
        $score += $postApplyVerificationCapability ? 5 : 0;
        $score += $verifiedStagingEvidence ? 5 : 0;

        return min(100, $score);
    }

    private function nextSafeAction(ReadinessStage $stage): string
    {
        return match ($stage) {
            ReadinessStage::DISABLED => 'no_action_disabled_supplier',
            ReadinessStage::SOURCE_NOT_CONFIGURED => 'configure_source_securely',
            ReadinessStage::DRIVER_REQUIRED => 'implement_driver',
            ReadinessStage::SOURCE_PROFILE_REQUIRED => 'define_feed_profile',
            ReadinessStage::PREVIEW_CONTRACT_READY => 'implement_preview',
            ReadinessStage::PREVIEW_READY => 'implement_controlled_staging',
            ReadinessStage::STAGING_APPLY_CONTRACT_READY => 'request_controlled_staging_approval',
            ReadinessStage::STAGING_PRESENT_UNVERIFIED => 'run_post_apply_verification',
            ReadinessStage::MAPPING_REVIEW_REQUIRED, ReadinessStage::STAGING_VERIFIED => 'perform_mapping_review',
            ReadinessStage::MANUAL_CREATE_CANDIDATE => 'consider_manual_create_sync',
            ReadinessStage::BLOCKED => 'inspect_configuration',
            ReadinessStage::UNKNOWN => 'perform_capability_audit',
        };
    }

    /** @return Collection<int, SupplierReadinessMatrixRow> */
    private function sortRows(Collection $rows, string $sort, string $direction): Collection
    {
        $descending = strtolower($direction) === 'desc';
        $sort = strtolower($sort);

        return $rows
            ->sort(function (SupplierReadinessMatrixRow $left, SupplierReadinessMatrixRow $right) use ($sort, $descending): int {
                $result = match ($sort) {
                    'supplier' => strcmp($left->supplierName, $right->supplierName),
                    'staging' => $left->stagingRowCount <=> $right->stagingRowCount,
                    'blockers' => count($left->blockers) <=> count($right->blockers),
                    default => (self::STAGE_ORDER[$left->readinessStage->value] ?? 999) <=> (self::STAGE_ORDER[$right->readinessStage->value] ?? 999),
                };

                if ($result === 0) {
                    $result = strcmp($left->supplierKey, $right->supplierKey);
                }

                return $descending ? -$result : $result;
            })
            ->values();
    }

    /** @return array<string, int> */
    private function stageCounts(Collection $rows): array
    {
        return $rows
            ->countBy(fn (SupplierReadinessMatrixRow $row): string => $row->readinessStage->value)
            ->sortKeys()
            ->map(fn (int $count): int => $count)
            ->all();
    }

    /** @return array<string, int> */
    private function issueCounts(Collection $rows, string $property): array
    {
        return $rows
            ->flatMap(fn (SupplierReadinessMatrixRow $row): array => array_map(
                fn (ValidationIssue $issue): string => $issue->code,
                $row->{$property},
            ))
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count): int => $count)
            ->all();
    }

    /** @return array<int, ValidationIssue> */
    private function reportIssues(Collection $rows, bool $globalUnsafe, array $recordsChanged, int $limit): array
    {
        $issues = $rows
            ->flatMap(fn (SupplierReadinessMatrixRow $row): array => [...$row->blockers, ...$row->warnings])
            ->sortBy(fn (ValidationIssue $issue): string => $issue->code)
            ->values()
            ->all();

        if ($globalUnsafe) {
            $issues[] = $this->issue('unsafe_catalog_sync_configuration', ValidationSeverity::BLOCKER);
        }

        if (array_filter($recordsChanged) !== []) {
            $issues[] = $this->issue('protected_record_count_changed', ValidationSeverity::BLOCKER);
        }

        return array_slice($issues, 0, $limit);
    }

    private function verdict(Collection $rows, bool $globalUnsafe, array $recordsChanged): ReadinessVerdict
    {
        if ($globalUnsafe) {
            return ReadinessVerdict::UNSAFE_CONFIGURATION;
        }

        if (array_filter($recordsChanged) !== []) {
            return ReadinessVerdict::AUDIT_FAILED;
        }

        if ($rows->isEmpty() || $rows->contains(fn (SupplierReadinessMatrixRow $row): bool => $row->requiresProductionReadOnlyAudit)) {
            return ReadinessVerdict::INCOMPLETE_INFORMATION;
        }

        return ReadinessVerdict::READY_FOR_REVIEW;
    }

    /** @return array<string, bool> */
    private function globalSafetyFlags(): array
    {
        return [
            'catalog_sync_create_enabled' => (bool) config('catalog_sync.create_enabled', true),
            'catalog_sync_update_enabled' => (bool) config('catalog_sync.update_enabled', false),
            'catalog_sync_sync_all_enabled' => (bool) config('catalog_sync.sync_all_enabled', false),
            'catalog_sync_auto_enabled' => (bool) config('catalog_sync.auto_enabled', false),
        ];
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        $counts = [];

        foreach (self::PROTECTED_TABLES as $table) {
            $counts[$table] = Schema::hasTable($table)
                ? (int) Schema::getConnection()->table($table)->count()
                : 0;
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function recordsChanged(array $before, array $after): array
    {
        $recordsChanged = [];

        foreach (self::PROTECTED_TABLES as $table) {
            $recordsChanged[$table] = abs((int) ($after[$table] ?? 0) - (int) ($before[$table] ?? 0));
        }

        $recordsChanged['catalog_sync'] = 0;

        return $recordsChanged;
    }

    private function sourceFormat(mixed $value): string
    {
        $value = $this->normalizedValue($value);

        return $value ?? 'unknown';
    }

    private function legacyDriverKey(string $sourceFormat, bool $available): ?string
    {
        if (! $available) {
            return null;
        }

        return match ($sourceFormat) {
            'xml' => 'legacy-xml-staging',
            'csv' => 'legacy-csv-staging',
            default => null,
        };
    }

    private function optionalString(mixed $value): ?string
    {
        return $this->normalizedValue($value);
    }

    private function supplierKey(array $capability): string
    {
        $key = $this->normalizedValue($capability['supplier_key'] ?? null);

        if ($key !== null && ! ctype_digit($key)) {
            return $key;
        }

        return 'supplier-'.hash('sha256', (string) ($capability['supplier_name'] ?? 'unknown'));
    }

    private function supplierSlug(array $capability): ?string
    {
        $key = $this->normalizedValue($capability['supplier_key'] ?? null);

        return $key !== null && ! ctype_digit($key) ? $key : null;
    }

    private function normalizedValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', strtolower($value)) === 1;
    }

    private function boundedLimit(mixed $value): int
    {
        return max(1, min((int) $value, 100));
    }

    private function issue(string $code, ValidationSeverity $severity): ValidationIssue
    {
        return new ValidationIssue(
            code: $code,
            severity: $severity,
            messageKey: 'supplier_onboarding.readiness.'.$code,
            blocking: $severity === ValidationSeverity::BLOCKER,
        );
    }
}
