<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CanonicalOnboardingData;
use App\Data\Suppliers\Onboarding\LocalSupplierSourceNormalizationPlanReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class LocalSupplierSourceNormalizationPlanner
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
        'supplier_import_runs',
        'import_jobs',
    ];

    private const ACTIVE_IMPORT_STATUSES = ['pending', 'queued', 'running', 'processing', 'started'];

    private const MATERIAL_RECORD_COUNT_DELTA_PERCENTAGE = 20.0;

    private const LOW_CONFIDENCE_THRESHOLD = 0.8;

    public function __construct(private readonly LocalSupplierSourceProfiler $profiler) {}

    /** @param array<string, mixed> $options */
    public function plan(array $options): LocalSupplierSourceNormalizationPlanReport
    {
        $startedAt = microtime(true);
        $flags = $this->globalSafetyFlags();
        $supplierInput = trim((string) ($options['supplier'] ?? ''));
        $source = trim((string) ($options['source'] ?? ''));
        $expected = $this->expectedState($options);

        if ($supplierInput === '') {
            return $this->failure('audit_failed', ['supplier_required'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        if ($source === '') {
            return $this->failure('invalid_local_source', ['local_source_required'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        if ($this->isRejectedSource($source)) {
            return $this->failure('invalid_local_source', ['remote_source_rejected'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        if (strtolower(trim((string) ($options['source_format'] ?? 'xml'))) !== 'xml') {
            return $this->failure('invalid_local_source', ['unsupported_source_format'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        $expectedSha256 = strtolower(trim((string) ($options['expected_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            return $this->failure('invalid_local_source', ['source_fingerprint_required'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        if ($expected === null) {
            return $this->failure('baseline_state_mismatch', ['expected_state_required'], $startedAt, globalSafetyFlags: $flags);
        }

        if (! $this->safeConfiguration($flags)) {
            return $this->failure('unsafe_configuration', ['unsafe_catalog_sync_flags'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        try {
            $supplier = $this->resolveSupplier($supplierInput);
        } catch (InvalidArgumentException $exception) {
            return $this->failure('audit_failed', [$exception->getMessage()], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        $protectedCountsBefore = $this->protectedCounts();
        $protectedFingerprintsBefore = $this->protectedStateFingerprints((int) $supplier->id);
        $observed = $this->observedState($supplier);
        $activeImport = $this->activeImportCheck((int) $supplier->id);
        $blockers = $this->baselineMismatches($expected, $observed);

        if (($observed['schedule_enabled'] ?? null) !== false) {
            $blockers[] = 'schedule_not_frozen';
        }

        if ($activeImport['state'] === 'active') {
            $blockers[] = 'active_import_detected';
        }

        if ($activeImport['state'] === 'unknown') {
            $blockers[] = 'import_state_unknown';
        }

        if (($observed['linked_count'] ?? 0) + ($observed['unlinked_count'] ?? 0) !== ($observed['staged_count'] ?? 0)) {
            $blockers[] = 'staging_count_reconciliation_mismatch';
        }

        $supplierSummary = $this->supplierSummary($supplier);
        $sourceSummary = ['file_name' => basename($source), 'source_format' => 'xml'];

        if ($blockers !== []) {
            return $this->failure(
                $this->failureVerdict($blockers),
                $blockers,
                $startedAt,
                supplier: $supplierSummary,
                source: $sourceSummary,
                sourceFingerprint: ['expected_sha256' => $expectedSha256, 'matches' => false],
                expectedState: $expected,
                observedState: $observed,
                activeImportCheck: $activeImport,
                globalSafetyFlags: $flags,
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }

        try {
            $profile = $this->profiler->profile([
                'supplier' => $supplier->slug ?: $supplier->id,
                'source' => $source,
                'source_format' => 'xml',
                'record_path' => $options['record_path'] ?? null,
                'expected_sha256' => $expectedSha256,
                'full_file' => true,
                'include_value_diagnostics' => true,
            ]);
        } catch (Throwable) {
            return $this->failure(
                'invalid_local_source',
                ['invalid_local_source'],
                $startedAt,
                supplier: $supplierSummary,
                source: $sourceSummary,
                sourceFingerprint: ['expected_sha256' => $expectedSha256, 'matches' => false],
                expectedState: $expected,
                observedState: $observed,
                activeImportCheck: $activeImport,
                globalSafetyFlags: $flags,
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }

        $profilePayload = $profile->toArray();
        $sourceFingerprint = (array) ($profilePayload['source_fingerprint'] ?? []);
        $sourceSummary = array_filter([
            'file_name' => data_get($profilePayload, 'source_metadata.file_name'),
            'source_format' => 'xml',
        ], static fn (mixed $value): bool => $value !== null);

        if (($profilePayload['verdict'] ?? null) !== 'source_profile_complete') {
            $profileBlockers = (array) ($profilePayload['blockers'] ?? []);
            $profileBlockers = $profileBlockers === [] ? ['profile_incomplete'] : $profileBlockers;

            return $this->failure(
                in_array('source_fingerprint_mismatch', $profileBlockers, true) ? 'invalid_local_source' : 'source_profile_incomplete',
                $profileBlockers,
                $startedAt,
                supplier: $supplierSummary,
                source: $sourceSummary,
                sourceFingerprint: $sourceFingerprint,
                expectedState: $expected,
                observedState: $observed,
                activeImportCheck: $activeImport,
                globalSafetyFlags: $flags,
                sourceProfile: $this->safeSourceProfile($profilePayload),
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }

        if (! is_file($source) || ! hash_equals((string) ($sourceFingerprint['sha256'] ?? ''), (string) hash_file('sha256', $source))) {
            return $this->failure(
                'invalid_local_source',
                ['source_changed_during_profile'],
                $startedAt,
                supplier: $supplierSummary,
                source: $sourceSummary,
                sourceFingerprint: $sourceFingerprint,
                expectedState: $expected,
                observedState: $observed,
                activeImportCheck: $activeImport,
                globalSafetyFlags: $flags,
                sourceProfile: $this->safeSourceProfile($profilePayload),
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }

        $sourceRecordCount = (int) data_get($profilePayload, 'parser_result.total_record_count', 0);
        $fieldCoverage = $this->fieldCoverage($profilePayload, $sourceRecordCount);
        $fieldCompatibility = $this->fieldCompatibility($fieldCoverage, (array) ($observed['legacy_field_coverage'] ?? []));
        $collisionPolicy = $this->collisionPolicy($profilePayload, $fieldCoverage, $sourceRecordCount);
        $offerFieldPlan = $this->offerFieldPlan($profilePayload, $fieldCoverage);
        $sourceWarnings = (array) ($profilePayload['warnings'] ?? []);
        $warnings = array_values(array_unique($sourceWarnings));
        $plannerBlockers = [];

        $recordCountDelta = $sourceRecordCount - (int) $observed['staged_count'];
        $recordCountDeltaPercentage = $this->recordCountDeltaPercentage($recordCountDelta, (int) $observed['staged_count']);
        $recordCountDrift = abs($recordCountDeltaPercentage) > self::MATERIAL_RECORD_COUNT_DELTA_PERCENTAGE
            ? 'warning'
            : ($recordCountDelta === 0 ? 'none' : 'informational');

        if ($recordCountDrift === 'warning') {
            $warnings[] = 'source_record_count_material_drift';
        }

        $lowConfidenceRoles = collect($fieldCoverage)
            ->filter(fn (array $coverage): bool => ($coverage['source_field_path'] ?? null) !== null && (float) ($coverage['confidence_score'] ?? 0) < self::LOW_CONFIDENCE_THRESHOLD)
            ->keys()
            ->values()
            ->all();
        if ($lowConfidenceRoles !== []) {
            $warnings[] = 'low_confidence_field_roles';
        }

        if (($fieldCoverage['supplier_sku']['source_field_path'] ?? null) === null || ($fieldCoverage['mpn']['source_field_path'] ?? null) === null) {
            $warnings[] = 'identifier_fields_require_review';
        }
        if (($fieldCoverage['supplier_category']['source_field_path'] ?? null) === null) {
            $warnings[] = 'category_field_requires_review';
        }

        $priceDiagnostics = $this->diagnosticsForRole($profilePayload, $fieldCoverage, 'price');
        $quantityDiagnostics = $this->diagnosticsForRole($profilePayload, $fieldCoverage, 'quantity');
        $eanDiagnostics = $this->diagnosticsForRole($profilePayload, $fieldCoverage, 'ean');

        if (($priceDiagnostics['negative_numeric_count'] ?? 0) > 0) {
            $plannerBlockers[] = 'negative_price_detected';
        }
        if (($quantityDiagnostics['negative_numeric_count'] ?? 0) > 0) {
            $plannerBlockers[] = 'negative_quantity_detected';
        }
        if (($priceDiagnostics['non_blank_count'] ?? 0) > ($priceDiagnostics['numeric_count'] ?? 0)) {
            $plannerBlockers[] = 'non_numeric_price_detected';
        }
        if (($priceDiagnostics['zero_numeric_count'] ?? 0) > 0) {
            $warnings[] = 'zero_price_requires_review';
        }
        if (($eanDiagnostics['non_blank_count'] ?? 0) > ($eanDiagnostics['digits_only_count'] ?? 0)) {
            $warnings[] = 'ean_format_requires_review';
        }

        $protectedCountsAfter = $this->protectedCounts();
        $protectedFingerprintsAfter = $this->protectedStateFingerprints((int) $supplier->id);
        $recordsChanged = $this->recordsChanged($protectedCountsBefore, $protectedCountsAfter);
        if ($protectedFingerprintsAfter !== $protectedFingerprintsBefore || array_sum($recordsChanged) !== 0) {
            $plannerBlockers[] = 'protected_state_changed';
        }

        $blockers = array_values(array_unique($plannerBlockers));
        $warnings = array_values(array_unique($warnings));
        $verdict = $this->planVerdict($blockers, $warnings, $fieldCoverage, $recordCountDrift);
        $unresolvedFields = array_values(array_unique(array_merge(
            (array) data_get($profilePayload, 'feed_profile_draft.unresolved_fields', []),
            $lowConfidenceRoles,
        )));

        return $this->report([
            'success' => $blockers === [],
            'verdict' => $verdict,
            'supplier' => $supplierSummary,
            'source' => $sourceSummary,
            'source_fingerprint' => $sourceFingerprint,
            'expected_state' => $expected,
            'observed_state' => $observed,
            'baseline_lock' => [
                'matches' => $this->baselineMismatches($expected, $observed) === [],
                'expected_state_required' => true,
                'schedule_must_remain_disabled' => true,
                'record_count_drift_classification' => $recordCountDrift,
                'material_record_count_delta_percentage' => self::MATERIAL_RECORD_COUNT_DELTA_PERCENTAGE,
            ],
            'active_import_check' => $activeImport,
            'global_safety_flags' => $flags,
            'source_profile' => $this->safeSourceProfile($profilePayload),
            'source_record_count' => $sourceRecordCount,
            'legacy_staging_count' => (int) $observed['staged_count'],
            'record_count_delta' => $recordCountDelta,
            'record_count_delta_percentage' => $recordCountDeltaPercentage,
            'field_coverage' => $fieldCoverage,
            'field_compatibility' => $fieldCompatibility,
            'identifier_strategy' => $this->identifierStrategy($collisionPolicy),
            'proposed_normalization_rules' => $this->normalizationRules(),
            'offer_field_plan' => $offerFieldPlan,
            'descriptive_field_policy' => $this->descriptiveFieldPolicy(),
            'category_mapping_policy' => $this->categoryMappingPolicy((int) $supplier->id),
            'attribute_policy' => $this->attributePolicy(),
            'image_policy' => $this->imagePolicy($fieldCoverage),
            'collision_policy' => $collisionPolicy,
            'unresolved_fields' => $unresolvedFields,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'issue_counts' => ['blockers' => count($blockers), 'warnings' => count($warnings)],
            'issues' => $this->issues($blockers, $warnings, $this->boundedLimit($options['issue_sample_limit'] ?? 20)),
            'protected_counts_before' => $protectedCountsBefore,
            'protected_counts_after' => $protectedCountsAfter,
            'protected_fingerprints_before' => $protectedFingerprintsBefore,
            'protected_fingerprints_after' => $protectedFingerprintsAfter,
            'records_changed' => $recordsChanged,
            'elapsed_seconds' => $this->elapsedSeconds($startedAt),
        ]);
    }

    /** @param array<string, mixed>|null $expectedState @param array<string, bool> $globalSafetyFlags */
    private function failure(
        string $verdict,
        array $blockers,
        float $startedAt,
        array $supplier = [],
        array $source = [],
        array $sourceFingerprint = [],
        ?array $expectedState = null,
        array $observedState = [],
        array $activeImportCheck = [],
        array $globalSafetyFlags = [],
        array $sourceProfile = [],
        array $protectedCountsBefore = [],
        array $protectedCountsAfter = [],
        array $protectedFingerprintsBefore = [],
        array $protectedFingerprintsAfter = [],
    ): LocalSupplierSourceNormalizationPlanReport {
        return $this->report([
            'success' => false,
            'verdict' => $verdict,
            'supplier' => $supplier,
            'source' => $source,
            'source_fingerprint' => $sourceFingerprint,
            'expected_state' => $expectedState ?? [],
            'observed_state' => $observedState,
            'baseline_lock' => ['matches' => false, 'expected_state_required' => true, 'schedule_must_remain_disabled' => true],
            'active_import_check' => $activeImportCheck,
            'global_safety_flags' => $globalSafetyFlags,
            'source_profile' => $sourceProfile,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => [],
            'issue_counts' => ['blockers' => count(array_unique($blockers)), 'warnings' => 0],
            'issues' => $this->issues($blockers, [], 20),
            'protected_counts_before' => $protectedCountsBefore,
            'protected_counts_after' => $protectedCountsAfter,
            'protected_fingerprints_before' => $protectedFingerprintsBefore,
            'protected_fingerprints_after' => $protectedFingerprintsAfter,
            'records_changed' => $this->zeroRecordsChanged(),
            'elapsed_seconds' => $this->elapsedSeconds($startedAt),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function report(array $data): LocalSupplierSourceNormalizationPlanReport
    {
        return new LocalSupplierSourceNormalizationPlanReport(
            success: (bool) ($data['success'] ?? false),
            verdict: (string) ($data['verdict'] ?? 'audit_failed'),
            supplier: (array) ($data['supplier'] ?? []),
            source: (array) ($data['source'] ?? []),
            sourceFingerprint: (array) ($data['source_fingerprint'] ?? []),
            expectedState: (array) ($data['expected_state'] ?? []),
            observedState: (array) ($data['observed_state'] ?? []),
            baselineLock: (array) ($data['baseline_lock'] ?? []),
            activeImportCheck: (array) ($data['active_import_check'] ?? []),
            globalSafetyFlags: (array) ($data['global_safety_flags'] ?? []),
            sourceProfile: (array) ($data['source_profile'] ?? []),
            sourceRecordCount: (int) ($data['source_record_count'] ?? 0),
            legacyStagingCount: (int) ($data['legacy_staging_count'] ?? 0),
            recordCountDelta: (int) ($data['record_count_delta'] ?? 0),
            recordCountDeltaPercentage: (float) ($data['record_count_delta_percentage'] ?? 0),
            fieldCoverage: (array) ($data['field_coverage'] ?? []),
            fieldCompatibility: (array) ($data['field_compatibility'] ?? []),
            identifierStrategy: (array) ($data['identifier_strategy'] ?? []),
            proposedNormalizationRules: (array) ($data['proposed_normalization_rules'] ?? []),
            offerFieldPlan: (array) ($data['offer_field_plan'] ?? []),
            descriptiveFieldPolicy: (array) ($data['descriptive_field_policy'] ?? []),
            categoryMappingPolicy: (array) ($data['category_mapping_policy'] ?? []),
            attributePolicy: (array) ($data['attribute_policy'] ?? []),
            imagePolicy: (array) ($data['image_policy'] ?? []),
            collisionPolicy: (array) ($data['collision_policy'] ?? []),
            unresolvedFields: (array) ($data['unresolved_fields'] ?? []),
            blockers: (array) ($data['blockers'] ?? []),
            warnings: (array) ($data['warnings'] ?? []),
            issueCounts: (array) ($data['issue_counts'] ?? []),
            issues: (array) ($data['issues'] ?? []),
            protectedCountsBefore: (array) ($data['protected_counts_before'] ?? []),
            protectedCountsAfter: (array) ($data['protected_counts_after'] ?? []),
            protectedStateFingerprintsBefore: (array) ($data['protected_fingerprints_before'] ?? []),
            protectedStateFingerprintsAfter: (array) ($data['protected_fingerprints_after'] ?? []),
            recordsChanged: (array) ($data['records_changed'] ?? $this->zeroRecordsChanged()),
            elapsedSeconds: (float) ($data['elapsed_seconds'] ?? 0.0001),
            peakMemoryBytes: memory_get_peak_usage(true),
        );
    }

    /** @param array<string, mixed> $options @return array<string, mixed>|null */
    private function expectedState(array $options): ?array
    {
        $required = [
            'expected_supplier_id',
            'expected_schedule_enabled',
            'expected_import_enabled',
            'expected_schedule_type',
            'expected_staged_count',
            'expected_linked_count',
            'expected_unlinked_count',
            'expected_last_import_at',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $options) || blank($options[$key])) {
                return null;
            }
        }

        $scheduleEnabled = $this->parseBoolean($options['expected_schedule_enabled']);
        $importEnabled = $this->parseBoolean($options['expected_import_enabled']);
        $lastImportAt = $this->normalizeDate($options['expected_last_import_at']);

        if ($scheduleEnabled === null || $importEnabled === null || $lastImportAt === null) {
            return null;
        }

        foreach (['expected_supplier_id', 'expected_staged_count', 'expected_linked_count', 'expected_unlinked_count'] as $key) {
            if (filter_var($options[$key], FILTER_VALIDATE_INT) === false || (int) $options[$key] < 0) {
                return null;
            }
        }

        $scheduleType = trim((string) $options['expected_schedule_type']);

        return $scheduleType === '' ? null : [
            'supplier_id' => (int) $options['expected_supplier_id'],
            'schedule_enabled' => $scheduleEnabled,
            'import_enabled' => $importEnabled,
            'schedule_type' => $scheduleType,
            'staged_count' => (int) $options['expected_staged_count'],
            'linked_count' => (int) $options['expected_linked_count'],
            'unlinked_count' => (int) $options['expected_unlinked_count'],
            'last_import_at' => $lastImportAt,
        ];
    }

    private function resolveSupplier(string $value): object
    {
        if (! Schema::hasTable('suppliers')) {
            throw new InvalidArgumentException('supplier_not_found');
        }

        $columns = collect(['id', 'company_name', 'slug', 'status', 'import_enabled', 'schedule_enabled', 'schedule_type', 'last_import_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('suppliers', $column))
            ->values()
            ->all();
        $query = DB::table('suppliers')->select($columns);

        if (is_numeric($value)) {
            $query->where('id', (int) $value);
        } else {
            $normalized = Str::lower($value);
            $query->where(function ($supplier) use ($normalized): void {
                $supplier->whereRaw('LOWER(slug) = ?', [$normalized])
                    ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
            });
        }

        $matches = $query->limit(2)->get();

        if ($matches->isEmpty()) {
            throw new InvalidArgumentException('supplier_not_found');
        }
        if ($matches->count() > 1) {
            throw new InvalidArgumentException('supplier_ambiguous');
        }

        return $matches->first();
    }

    /** @return array<string, mixed> */
    private function observedState(object $supplier): array
    {
        $stagedCount = $this->supplierProductCount((int) $supplier->id);
        $linkedCount = $this->supplierProductCount((int) $supplier->id, true);
        $unlinkedCount = $this->supplierProductCount((int) $supplier->id, false);

        return [
            'supplier_id' => (int) $supplier->id,
            'schedule_enabled' => (bool) ($supplier->schedule_enabled ?? false),
            'import_enabled' => Schema::hasColumn('suppliers', 'import_enabled') ? (bool) ($supplier->import_enabled ?? false) : null,
            'schedule_type' => Schema::hasColumn('suppliers', 'schedule_type') ? ($supplier->schedule_type ?: null) : null,
            'staged_count' => $stagedCount,
            'linked_count' => $linkedCount,
            'unlinked_count' => $unlinkedCount,
            'last_import_at' => $this->normalizeDate($supplier->last_import_at ?? null),
            'legacy_field_coverage' => $this->legacyFieldCoverage((int) $supplier->id, $stagedCount),
        ];
    }

    /** @return array<string, mixed> */
    private function activeImportCheck(int $supplierId): array
    {
        $checked = [];
        $active = 0;
        $unknown = 0;

        foreach (['supplier_import_runs', 'import_jobs'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'supplier_id') || ! Schema::hasColumn($table, 'status')) {
                continue;
            }

            $checked[] = $table;
            foreach (DB::table($table)->where('supplier_id', $supplierId)->pluck('status') as $status) {
                $normalized = Str::lower(trim((string) $status));
                if ($normalized === '' || ! in_array($normalized, ['pending', 'queued', 'running', 'processing', 'started', 'completed', 'completed_with_warnings', 'failed', 'skipped', 'cancelled'], true)) {
                    $unknown++;
                } elseif (in_array($normalized, self::ACTIVE_IMPORT_STATUSES, true)) {
                    $active++;
                }
            }
        }

        return [
            'state' => $active > 0 ? 'active' : ($checked === [] || $unknown > 0 ? 'unknown' : 'clear'),
            'active_count' => $active,
            'unknown_state_count' => $unknown,
            'checked_sources' => $checked,
        ];
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $observed @return array<int, string> */
    private function baselineMismatches(array $expected, array $observed): array
    {
        $mapping = [
            'supplier_id' => 'expected_supplier_id_mismatch',
            'schedule_enabled' => 'expected_schedule_state_mismatch',
            'import_enabled' => 'expected_import_state_mismatch',
            'schedule_type' => 'expected_schedule_type_mismatch',
            'staged_count' => 'expected_staged_count_mismatch',
            'linked_count' => 'expected_linked_count_mismatch',
            'unlinked_count' => 'expected_unlinked_count_mismatch',
            'last_import_at' => 'expected_last_import_at_mismatch',
        ];
        $mismatches = [];

        foreach ($mapping as $key => $reason) {
            if (($expected[$key] ?? null) !== ($observed[$key] ?? null)) {
                $mismatches[] = $reason;
            }
        }

        return $mismatches;
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private function safeSourceProfile(array $profile): array
    {
        return [
            'schema_version' => $profile['schema_version'] ?? null,
            'verdict' => $profile['verdict'] ?? null,
            'parser_result' => $profile['parser_result'] ?? [],
            'record_path_analysis' => $profile['record_path_analysis'] ?? [],
            'likely_field_roles' => $profile['likely_field_roles'] ?? [],
            'feed_profile_draft' => $profile['feed_profile_draft'] ?? [],
            'records_changed' => $profile['records_changed'] ?? $this->zeroRecordsChanged(),
        ];
    }

    /** @param array<string, mixed> $profile @return array<string, array<string, mixed>> */
    private function fieldCoverage(array $profile, int $recordCount): array
    {
        $fields = (array) data_get($profile, 'field_inventory.fields', []);
        $roles = (array) ($profile['likely_field_roles'] ?? []);
        $definitions = [
            'supplier_sku' => ['profile_role' => 'sku', 'future_staging_field' => 'supplier_sku', 'proposal' => 'trim_unicode_whitespace_and_nfc_preserve_semantics'],
            'ean' => ['profile_role' => 'ean', 'future_staging_field' => 'ean', 'proposal' => 'trim_preserve_leading_zeroes_digits_only_review'],
            'mpn' => ['profile_role' => 'mpn', 'future_staging_field' => 'mpn', 'proposal' => 'trim_unicode_whitespace_nfc_preserve_case'],
            'product_name' => ['profile_role' => 'name', 'future_staging_field' => 'name', 'proposal' => 'staging_metadata_or_manual_review_only'],
            'brand' => ['profile_role' => 'brand', 'future_staging_field' => 'brand_name', 'proposal' => 'staging_metadata_or_comparison_only'],
            'supplier_category' => ['profile_role' => 'category', 'future_staging_field' => 'category_name', 'proposal' => 'review_only_mapping_candidate'],
            'price' => ['profile_role' => 'price', 'future_staging_field' => 'price', 'proposal' => 'locale_independent_decimal_no_currency_conversion'],
            'currency' => ['profile_role' => 'currency', 'future_staging_field' => 'currency', 'proposal' => 'uppercase_iso_code_no_default_currency'],
            'quantity' => ['profile_role' => 'quantity', 'future_staging_field' => 'quantity', 'proposal' => 'safe_integer_negative_rejected'],
            'availability' => ['profile_role' => 'availability', 'future_staging_field' => 'external_availability', 'proposal' => 'non_persisted_mapping_requires_human_approval'],
            'description' => ['profile_role' => null, 'future_staging_field' => null, 'proposal' => 'not_used_without_content_governance_phase'],
        ];

        $coverage = [];
        foreach ($definitions as $role => $definition) {
            $candidate = $definition['profile_role'] === null
                ? $this->descriptionField($fields)
                : (array) ($roles[$definition['profile_role']] ?? []);
            $path = $candidate['path'] ?? null;
            $field = $path !== null ? (array) ($fields[$path] ?? []) : [];
            $present = (int) ($field['presence_count'] ?? 0) - (int) ($field['blank_count'] ?? 0);
            $present = max(0, $present);

            $coverage[$role] = [
                'source_field_path' => $path,
                'confidence_score' => (float) ($candidate['confidence'] ?? ($path !== null ? 0.5 : 0)),
                'records_present' => $present,
                'records_missing' => max(0, $recordCount - $present),
                'coverage_percentage' => $this->percentage($present, $recordCount),
                'normalization_proposal' => $definition['proposal'],
                'review_required' => true,
                'intended_future_staging_field' => $definition['future_staging_field'],
                'catalog_mutation_allowed' => false,
            ];
        }

        $imagePaths = (array) ($roles['image_paths'] ?? []);
        $imagePresent = collect($imagePaths)->map(fn (string $path): int => max(0, (int) data_get($fields, $path.'.presence_count', 0) - (int) data_get($fields, $path.'.blank_count', 0)))->max() ?? 0;
        $coverage['image_paths'] = [
            'source_field_paths' => $imagePaths,
            'confidence_score' => $imagePaths === [] ? 0.0 : 1.0,
            'records_present' => $imagePresent,
            'records_missing' => max(0, $recordCount - $imagePresent),
            'coverage_percentage' => $this->percentage($imagePresent, $recordCount),
            'normalization_proposal' => 'detect_field_path_only_no_url_output_or_image_action',
            'review_required' => true,
            'intended_future_staging_field' => null,
            'catalog_mutation_allowed' => false,
        ];

        return $coverage;
    }

    /** @param array<string, array<string, mixed>> $fields @return array<string, mixed> */
    private function descriptionField(array $fields): array
    {
        foreach ($fields as $path => $field) {
            if (preg_match('/description|details|longtext/i', $path) === 1) {
                return ['path' => $path, 'confidence' => 1.0];
            }
        }

        return [];
    }

    /** @param array<string, array<string, mixed>> $coverage @param array<string, mixed> $legacy @return array<string, array<string, mixed>> */
    private function fieldCompatibility(array $coverage, array $legacy): array
    {
        $compatibility = [];
        foreach ($coverage as $role => $source) {
            $legacyKey = match ($role) {
                'product_name' => 'name',
                'brand' => 'brand_name',
                'supplier_category' => 'category_name',
                default => $role,
            };
            $legacyCoverage = (array) ($legacy[$legacyKey] ?? []);
            $sourceAvailable = ($source['source_field_path'] ?? null) !== null
                || (array) ($source['source_field_paths'] ?? []) !== [];

            $compatibility[$role] = [
                'source_available' => $sourceAvailable,
                'source_coverage_percentage' => (float) ($source['coverage_percentage'] ?? 0),
                'legacy_staging_coverage_percentage' => (float) ($legacyCoverage['coverage_percentage'] ?? 0),
                'coverage_difference_percentage' => round(
                    (float) ($source['coverage_percentage'] ?? 0) - (float) ($legacyCoverage['coverage_percentage'] ?? 0),
                    4,
                ),
                'comparison' => $sourceAvailable ? 'observed_difference_requires_human_review' : 'source_field_missing_requires_human_review',
                'catalog_mutation_allowed' => false,
            ];
        }

        return $compatibility;
    }

    /** @param array<string, mixed> $profile @param array<string, array<string, mixed>> $coverage @return array<string, mixed> */
    private function collisionPolicy(array $profile, array $coverage, int $recordCount): array
    {
        $sku = $this->diagnosticsForRole($profile, $coverage, 'supplier_sku');
        $ean = $this->diagnosticsForRole($profile, $coverage, 'ean');
        $mpn = $this->diagnosticsForRole($profile, $coverage, 'mpn');

        return [
            'duplicate_supplier_sku_risk' => $this->duplicateRisk($sku),
            'duplicate_ean_risk' => $this->duplicateRisk($ean),
            'duplicate_mpn_risk' => $this->duplicateRisk($mpn),
            'brand_mpn_collision_risk' => ['status' => 'diagnostic_only_not_auto_matched', 'group_count' => 0],
            'records_missing_all_primary_identifiers' => max(0, $recordCount - max((int) ($coverage['supplier_sku']['records_present'] ?? 0), (int) ($coverage['ean']['records_present'] ?? 0), (int) ($coverage['mpn']['records_present'] ?? 0))),
            'conflicting_candidate_identifiers' => 0,
            'automatic_link_merge_delete_or_correction_allowed' => false,
            'samples' => [
                'bounded_hashes_only' => true,
                'values_emitted' => false,
                'diagnostic_bounds' => (array) data_get($profile, 'field_inventory.value_diagnostics_meta', []),
            ],
        ];
    }

    /** @param array<string, mixed> $diagnostics @return array<string, mixed> */
    private function duplicateRisk(array $diagnostics): array
    {
        return [
            'exact_duplicate_groups' => (array) ($diagnostics['exact_duplicate_groups'] ?? ['group_count' => 0, 'duplicate_row_count' => 0]),
            'case_normalized_duplicate_groups' => (array) ($diagnostics['case_normalized_duplicate_groups'] ?? ['group_count' => 0, 'duplicate_row_count' => 0]),
            'whitespace_normalized_duplicate_groups' => (array) ($diagnostics['whitespace_normalized_duplicate_groups'] ?? ['group_count' => 0, 'duplicate_row_count' => 0]),
            'diagnostics_truncated' => (bool) ($diagnostics['value_diagnostics_truncated'] ?? false),
        ];
    }

    /** @param array<string, mixed> $profile @param array<string, array<string, mixed>> $coverage @return array<string, mixed> */
    private function offerFieldPlan(array $profile, array $coverage): array
    {
        return [
            'price' => [
                'field_coverage' => $coverage['price'] ?? [],
                'diagnostics' => $this->diagnosticsForRole($profile, $coverage, 'price'),
                'rules' => ['locale_independent_decimal', 'no_currency_conversion', 'no_vat_inference', 'zero_price_requires_review', 'negative_price_blocks'],
                'catalog_mutation_allowed' => false,
            ],
            'currency' => [
                'field_coverage' => $coverage['currency'] ?? [],
                'rules' => ['uppercase_iso_code', 'no_default_currency', 'no_currency_conversion'],
                'catalog_mutation_allowed' => false,
            ],
            'quantity' => [
                'field_coverage' => $coverage['quantity'] ?? [],
                'diagnostics' => $this->diagnosticsForRole($profile, $coverage, 'quantity'),
                'rules' => ['safe_integer', 'negative_quantity_blocks', 'missing_quantity_unresolved'],
                'catalog_mutation_allowed' => false,
            ],
            'availability' => [
                'field_coverage' => $coverage['availability'] ?? [],
                'rules' => ['non_persisted_mapping_proposal', 'human_approval_required', 'no_catalog_availability_update'],
                'catalog_mutation_allowed' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $profile @param array<string, array<string, mixed>> $coverage @return array<string, mixed> */
    private function diagnosticsForRole(array $profile, array $coverage, string $role): array
    {
        $path = $coverage[$role]['source_field_path'] ?? null;

        return is_string($path) ? (array) data_get($profile, 'field_inventory.value_diagnostics.'.$path, []) : [];
    }

    /** @return array<string, mixed> */
    private function identifierStrategy(array $collisionPolicy): array
    {
        return [
            'candidate_precedence_for_human_review' => ['supplier_sku', 'ean_when_valid_and_present', 'brand_plus_mpn_diagnostic_only'],
            'supplier_sku' => ['trim_unicode_whitespace', 'normalize_nfc_when_supported', 'preserve_source_semantics', 'no_invention_or_silent_truncation'],
            'ean' => ['trim_whitespace', 'preserve_leading_zeroes', 'digits_only_after_trim', 'missing_allowed_but_reviewable', 'no_checksum_autocorrection'],
            'mpn' => ['trim_unicode_whitespace', 'normalize_nfc_when_supported', 'preserve_storage_case', 'case_insensitive_diagnostics_only'],
            'collision_diagnostics' => $collisionPolicy,
            'automatic_catalog_matching_or_linking_allowed' => false,
        ];
    }

    /** @return array<string, array<int, string>> */
    private function normalizationRules(): array
    {
        return [
            'supplier_sku' => ['trim_unicode_whitespace', 'normalize_nfc_when_supported', 'preserve_source_value', 'detect_exact_case_and_whitespace_duplicates'],
            'ean' => ['trim_whitespace', 'preserve_leading_zeroes', 'accept_digits_only', 'report_lengths_and_duplicates', 'do_not_invent_or_checksum_correct'],
            'mpn' => ['trim_unicode_whitespace', 'normalize_nfc_when_supported', 'preserve_original_case', 'diagnostic_case_normalized_duplicates_only'],
            'price' => ['parse_locale_independent_decimal', 'no_currency_conversion', 'no_vat_inference', 'zero_price_reviewable', 'negative_or_non_numeric_blocks'],
            'currency' => ['uppercase_iso_code', 'no_default_currency'],
            'quantity' => ['parse_safe_integer', 'negative_quantity_rejected', 'missing_quantity_unresolved'],
            'availability' => ['propose_non_persisted_mapping', 'human_approval_required', 'no_stock_inference_without_mapping'],
        ];
    }

    /** @return array<string, mixed> */
    private function descriptiveFieldPolicy(): array
    {
        return [
            'product_name' => ['allowed_future_use' => 'staging_metadata_or_manual_review_only', 'catalog_overwrite' => false],
            'brand' => ['allowed_future_use' => 'staging_metadata_or_comparison_only', 'catalog_overwrite' => false],
            'description' => ['allowed_future_use' => 'none_until_separate_content_governance_phase', 'catalog_overwrite' => false],
            'slug' => ['supplier_sourced' => false, 'catalog_overwrite' => false],
            'seo' => ['supplier_sourced' => false, 'catalog_overwrite' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function categoryMappingPolicy(int $supplierId): array
    {
        $counts = ['total' => 0, 'approved' => 0, 'pending_review' => 0, 'rejected' => 0];
        if (Schema::hasTable('supplier_category_mappings') && Schema::hasColumn('supplier_category_mappings', 'supplier_id')) {
            $query = DB::table('supplier_category_mappings')->where('supplier_id', $supplierId);
            $counts['total'] = $query->count();
            foreach (['approved', 'pending_review', 'rejected'] as $status) {
                $counts[$status] = (clone $query)->where('status', $status)->count();
            }
        }

        return [
            'current_counts' => $counts,
            'new_mapping_persisted' => false,
            'mapping_approved_or_rejected' => false,
            'supplier_category_overwrites_internal_category' => false,
            'candidate_fields_require_human_review' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function attributePolicy(): array
    {
        return [
            'supplier_attribute_written_to_catalog' => false,
            'attribute_definition_created' => false,
            'attribute_value_created' => false,
            'product_attribute_assignment_created' => false,
            'future_interpretation_requires_separate_reviewed_phase' => true,
        ];
    }

    /** @param array<string, array<string, mixed>> $coverage @return array<string, mixed> */
    private function imagePolicy(array $coverage): array
    {
        return [
            'detected_field_paths' => (array) ($coverage['image_paths']['source_field_paths'] ?? []),
            'image_import_enabled' => false,
            'image_urls_emitted' => false,
            'image_downloaded' => false,
            'remote_validation_performed' => false,
            'product_image_modified' => false,
            'media_record_created' => false,
            'future_image_handling_requires_separate_phase' => true,
        ];
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        $counts = [];
        foreach (self::PROTECTED_TABLES as $table) {
            $counts[$table] = Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        }
        $counts['catalog_sync'] = 0;

        return $counts;
    }

    /** @return array<string, string> */
    private function protectedStateFingerprints(int $supplierId): array
    {
        $fingerprints = [];
        foreach (self::PROTECTED_TABLES as $table) {
            $fingerprints[$table] = $this->tableFingerprint($table);
        }
        $fingerprints['catalog_sync'] = hash('sha256', 'catalog_sync_read_only');
        $fingerprints['selected_supplier'] = hash('sha256', CanonicalOnboardingData::encode($this->selectedSupplierFingerprintPayload($supplierId)));
        $fingerprints['selected_supplier_products'] = $this->supplierProductsFingerprint($supplierId);

        ksort($fingerprints);

        return $fingerprints;
    }

    private function tableFingerprint(string $table): string
    {
        if (! Schema::hasTable($table)) {
            return 'missing';
        }

        $columns = collect(['id', 'supplier_id', 'product_id', 'category_id', 'product_attribute_id', 'attribute_value_id', 'status', 'created_at', 'updated_at', 'deleted_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn($table, $column))
            ->values()
            ->all();
        if ($columns === []) {
            return hash('sha256', $table.':empty-schema');
        }

        $query = DB::table($table)->select($columns);
        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }
        $hash = hash_init('sha256');
        hash_update($hash, $table."\n");
        foreach ($query->cursor() as $row) {
            hash_update($hash, CanonicalOnboardingData::encode((array) $row)."\n");
        }

        return hash_final($hash);
    }

    /** @return array<string, mixed> */
    private function selectedSupplierFingerprintPayload(int $supplierId): array
    {
        if (! Schema::hasTable('suppliers')) {
            return ['missing' => true];
        }

        $columns = collect(['id', 'slug', 'company_name', 'status', 'import_enabled', 'schedule_enabled', 'schedule_type', 'last_import_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('suppliers', $column))
            ->values()
            ->all();

        return (array) (DB::table('suppliers')->where('id', $supplierId)->select($columns)->first() ?? ['missing' => true]);
    }

    private function supplierProductsFingerprint(int $supplierId): string
    {
        if (! Schema::hasTable('supplier_products')) {
            return 'missing';
        }

        $columns = collect(['id', 'supplier_id', 'product_id', 'payload_hash', 'status', 'received_at', 'updated_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('supplier_products', $column))
            ->values()
            ->all();
        $hash = hash_init('sha256');
        foreach (DB::table('supplier_products')->where('supplier_id', $supplierId)->select($columns)->orderBy('id')->cursor() as $row) {
            hash_update($hash, CanonicalOnboardingData::encode((array) $row)."\n");
        }

        return hash_final($hash);
    }

    /** @return array<string, int> */
    private function zeroRecordsChanged(): array
    {
        return array_fill_keys([...self::PROTECTED_TABLES, 'catalog_sync'], 0);
    }

    /** @param array<string, int> $before @param array<string, int> $after @return array<string, int> */
    private function recordsChanged(array $before, array $after): array
    {
        $changed = [];
        foreach ($before as $table => $count) {
            $changed[$table] = abs((int) ($after[$table] ?? 0) - $count);
        }

        return $changed;
    }

    private function supplierProductCount(int $supplierId, ?bool $linked = null): int
    {
        if (! Schema::hasTable('supplier_products')) {
            return 0;
        }
        $query = DB::table('supplier_products')->where('supplier_id', $supplierId);
        if ($linked === true) {
            $query->whereNotNull('product_id');
        } elseif ($linked === false) {
            $query->whereNull('product_id');
        }

        return $query->count();
    }

    /** @return array<string, array<string, float|int>> */
    private function legacyFieldCoverage(int $supplierId, int $total): array
    {
        $columns = ['supplier_sku', 'ean', 'mpn', 'name', 'brand_name', 'category_name', 'price', 'currency', 'quantity', 'availability'];
        $coverage = [];
        foreach ($columns as $column) {
            if (! Schema::hasTable('supplier_products') || ! Schema::hasColumn('supplier_products', $column)) {
                $coverage[$column] = ['records_present' => 0, 'records_missing' => $total, 'coverage_percentage' => 0.0];

                continue;
            }
            $present = DB::table('supplier_products')->where('supplier_id', $supplierId)->whereNotNull($column)->where($column, '!=', '')->count();
            $coverage[$column] = ['records_present' => $present, 'records_missing' => max(0, $total - $present), 'coverage_percentage' => $this->percentage($present, $total)];
        }

        return $coverage;
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

    /** @param array<string, bool> $flags */
    private function safeConfiguration(array $flags): bool
    {
        return $flags === [
            'catalog_sync_create_enabled' => true,
            'catalog_sync_update_enabled' => false,
            'catalog_sync_sync_all_enabled' => false,
            'catalog_sync_auto_enabled' => false,
        ];
    }

    private function isRejectedSource(string $source): bool
    {
        if (str_contains($source, "\0") || preg_match('/^(?:\\\\\\\\|\/\/)/', $source) === 1) {
            return true;
        }
        if (preg_match('/^(?:https?|ftp|sftp|file|php|data|phar|zip|glob):\/\//i', $source) === 1) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $source) === 1 && preg_match('/^[a-z]:[\\\\\/]/i', $source) !== 1;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($value) ? $value : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function percentage(int $numerator, int $denominator): float
    {
        return $denominator <= 0 ? 0.0 : round(($numerator / $denominator) * 100, 4);
    }

    private function recordCountDeltaPercentage(int $delta, int $baseline): float
    {
        return $baseline <= 0 ? ($delta === 0 ? 0.0 : 100.0) : round(($delta / $baseline) * 100, 4);
    }

    /** @param array<int, string> $blockers */
    private function failureVerdict(array $blockers): string
    {
        return match (true) {
            in_array('unsafe_catalog_sync_flags', $blockers, true) => 'unsafe_configuration',
            in_array('schedule_not_frozen', $blockers, true) => 'schedule_not_frozen',
            in_array('active_import_detected', $blockers, true) => 'active_import_detected',
            default => 'baseline_state_mismatch',
        };
    }

    /** @param array<int, string> $blockers @param array<int, string> $warnings @param array<string, array<string, mixed>> $coverage */
    private function planVerdict(array $blockers, array $warnings, array $coverage, string $recordCountDrift): string
    {
        if ($blockers !== []) {
            return 'audit_failed';
        }
        if ($recordCountDrift === 'warning') {
            return 'plan_requires_source_review';
        }
        if (in_array('identifier_fields_require_review', $warnings, true) || in_array('low_confidence_field_roles', $warnings, true)) {
            return 'plan_requires_identifier_review';
        }
        if (($coverage['supplier_category']['source_field_path'] ?? null) === null) {
            return 'plan_requires_mapping_review';
        }

        return 'plan_ready_for_human_review';
    }

    /** @param array<int, string> $blockers @param array<int, string> $warnings @return array<int, array<string, string>> */
    private function issues(array $blockers, array $warnings, int $limit): array
    {
        $issues = array_merge(
            array_map(fn (string $code): array => ['code' => $code, 'severity' => 'blocker'], array_values(array_unique($blockers))),
            array_map(fn (string $code): array => ['code' => $code, 'severity' => 'warning'], array_values(array_unique($warnings))),
        );
        usort($issues, fn (array $left, array $right): int => ($left['severity'] <=> $right['severity']) ?: ($left['code'] <=> $right['code']));

        return array_slice($issues, 0, $limit);
    }

    private function boundedLimit(mixed $value): int
    {
        return max(1, min(20, (int) $value ?: 20));
    }

    /** @return array<string, mixed> */
    private function supplierSummary(object $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'key' => (string) ($supplier->slug ?: $supplier->id),
            'name' => (string) ($supplier->company_name ?? ''),
            'role' => Str::lower((string) ($supplier->slug ?? '')) === 'apcom' ? 'supplier_1_historically_integrated' : 'existing_supplier',
        ];
    }

    private function elapsedSeconds(float $startedAt): float
    {
        return round(max(0.0001, microtime(true) - $startedAt), 6);
    }
}
