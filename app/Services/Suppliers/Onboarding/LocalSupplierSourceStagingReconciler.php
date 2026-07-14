<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CanonicalOnboardingData;
use App\Data\Suppliers\Onboarding\LocalSupplierSourceStagingReconciliationReport;
use App\Data\Suppliers\Onboarding\SupplierSourceFieldSemanticsProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use XMLReader;

/**
 * Reconciles a profiled local source to supplier staging without persisting data.
 */
final class LocalSupplierSourceStagingReconciler
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

    private const HASH_NAMESPACE = 'local-supplier-source-staging-reconciliation-v1';

    private const OFFICIAL_SEMANTICS_PROFILE = 'apcom-official-v1';

    private const OBSERVED_SEMANTICS_PROFILE = 'apcom-observed-stock-v1';

    private const PREVIOUS_STRICT_FAILURE_REFERENCE = [
        'runtime_report_path' => 'storage/app/imports/apcom-audit/reports/apcom_official_reconciliation_20260714T141523Z.json',
        'report_sha256' => '86de4ce6d79093954c22eaccfb5ac063d7168cfaf2d0c2228af5fce13baae2cc',
        'verdict' => 'audit_failed',
        'blocker' => 'invalid_stock_semantics_detected',
        'records_changed' => 'zero',
        'operator_supplied_evidence_only' => true,
        'loaded_at_runtime' => false,
    ];

    public function __construct(
        private readonly LocalSupplierSourceProfiler $profiler,
        private readonly SupplierSourceFieldSemanticsRegistry $semanticsRegistry,
        private readonly SupplierImportActivityInspector $importActivityInspector,
    ) {}

    /** @param array<string, mixed> $options */
    public function reconcile(array $options): LocalSupplierSourceStagingReconciliationReport
    {
        $startedAt = microtime(true);
        $flags = $this->globalSafetyFlags();
        $supplierInput = trim((string) ($options['supplier'] ?? ''));
        $source = trim((string) ($options['source'] ?? ''));
        $profileKey = trim((string) ($options['semantics_profile'] ?? ''));
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
        if (! ((bool) ($options['full_file'] ?? false))) {
            return $this->failure('invalid_local_source', ['full_file_required'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
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

        $semantics = $this->semanticsRegistry->find($profileKey);
        if ($semantics === null) {
            return $this->failure('unknown_semantics_profile', ['unknown_semantics_profile'], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        try {
            $supplier = $this->resolveSupplier($supplierInput);
        } catch (InvalidArgumentException $exception) {
            return $this->failure('audit_failed', [$exception->getMessage()], $startedAt, globalSafetyFlags: $flags, expectedState: $expected);
        }

        $supplierSummary = $this->supplierSummary($supplier);
        if (Str::lower((string) ($supplier->slug ?? '')) !== $semantics->supplierKey) {
            return $this->failure('semantics_supplier_mismatch', ['semantics_supplier_mismatch'], $startedAt, supplier: $supplierSummary, globalSafetyFlags: $flags, expectedState: $expected, semanticsProfile: $semantics->toArray());
        }

        $explicitRecordPath = $this->normalizePath($options['record_path'] ?? null);
        if ($explicitRecordPath !== null && $this->comparablePath($explicitRecordPath) !== $this->comparablePath($semantics->recordPath)) {
            return $this->failure('semantics_record_path_mismatch', ['semantics_record_path_mismatch'], $startedAt, supplier: $supplierSummary, globalSafetyFlags: $flags, expectedState: $expected, semanticsProfile: $semantics->toArray());
        }

        $protectedCountsBefore = $this->protectedCounts();
        $protectedFingerprintsBefore = $this->protectedStateFingerprints((int) $supplier->id);
        $observed = $this->observedState($supplier);
        $activeImport = $this->importActivityInspector->inspect((int) $supplier->id);
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
                semanticsProfile: $semantics->toArray(),
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
                'record_path' => $semantics->recordPath,
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
                semanticsProfile: $semantics->toArray(),
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }

        $profilePayload = $profile->toArray();
        $sourceFingerprint = (array) ($profilePayload['source_fingerprint'] ?? []);
        $sourceSummary = [
            'file_name' => (string) data_get($profilePayload, 'source_metadata.file_name', basename($source)),
            'source_format' => 'xml',
        ];

        if (($profilePayload['verdict'] ?? null) !== 'source_profile_complete') {
            $profileBlockers = (array) ($profilePayload['blockers'] ?? []);

            return $this->failure(
                in_array('source_fingerprint_mismatch', $profileBlockers, true) ? 'invalid_local_source' : 'source_profile_incomplete',
                $profileBlockers === [] ? ['profile_incomplete'] : $profileBlockers,
                $startedAt,
                supplier: $supplierSummary,
                source: $sourceSummary,
                sourceFingerprint: $sourceFingerprint,
                expectedState: $expected,
                observedState: $observed,
                activeImportCheck: $activeImport,
                globalSafetyFlags: $flags,
                semanticsProfile: $semantics->toArray(),
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
                semanticsProfile: $semantics->toArray(),
                sourceProfile: $this->safeSourceProfile($profilePayload),
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }

        try {
            // A second stream is necessary to compare source identities in memory; values never enter the report.
            $sourceRows = $this->scanSourceRows($source, $semantics);
        } catch (RuntimeException $exception) {
            return $this->failure(
                'invalid_local_source',
                [$exception->getMessage()],
                $startedAt,
                supplier: $supplierSummary,
                source: $sourceSummary,
                sourceFingerprint: $sourceFingerprint,
                expectedState: $expected,
                observedState: $observed,
                activeImportCheck: $activeImport,
                globalSafetyFlags: $flags,
                semanticsProfile: $semantics->toArray(),
                sourceProfile: $this->safeSourceProfile($profilePayload),
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }

        $profileValidation = $this->validateProfileFields($profilePayload, $semantics);
        $semanticsValidation = $this->validateSourceSemantics($sourceRows, $semantics);
        try {
            $stagingRows = $this->stagingRows((int) $supplier->id);
        } catch (RuntimeException $exception) {
            return $this->failure(
                'staging_unavailable',
                [$exception->getMessage()],
                $startedAt,
                supplier: $supplierSummary,
                source: $sourceSummary,
                sourceFingerprint: $sourceFingerprint,
                expectedState: $expected,
                observedState: $observed,
                activeImportCheck: $activeImport,
                globalSafetyFlags: $flags,
                semanticsProfile: $semantics->toArray(),
                sourceProfile: $this->safeSourceProfile($profilePayload),
                protectedCountsBefore: $protectedCountsBefore,
                protectedCountsAfter: $protectedCountsBefore,
                protectedFingerprintsBefore: $protectedFingerprintsBefore,
                protectedFingerprintsAfter: $protectedFingerprintsBefore,
            );
        }
        $reconciliation = $this->reconcileRows($sourceRows, $stagingRows, $this->boundedLimit($options['sample_limit'] ?? 20));

        $blockers = array_values(array_unique(array_merge($profileValidation['blockers'], $semanticsValidation['blockers'])));
        $warnings = array_values(array_unique(array_merge($profileValidation['warnings'], $semanticsValidation['warnings'], $reconciliation['warnings'])));
        $recordCountDelta = count($sourceRows) - (int) $observed['staged_count'];

        if ($recordCountDelta !== 0) {
            $warnings[] = 'source_staging_count_delta_requires_review';
        }

        $protectedCountsAfter = $this->protectedCounts();
        $protectedFingerprintsAfter = $this->protectedStateFingerprints((int) $supplier->id);
        $recordsChanged = $this->recordsChanged($protectedCountsBefore, $protectedCountsAfter);
        if ($protectedFingerprintsBefore !== $protectedFingerprintsAfter || array_sum($recordsChanged) !== 0) {
            $blockers[] = 'protected_state_changed';
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $reconciliationContinued = $semantics->usesObservedNumericStockContract()
            && $blockers === []
            && (bool) ($semanticsValidation['observed_numeric_contract_valid'] ?? false);
        $stockSemanticsDiscrepancy = $this->stockSemanticsDiscrepancy(
            $semantics,
            (array) data_get($semanticsValidation, 'aggregates.stock', []),
            $reconciliationContinued,
        );

        return $this->report(
            success: $blockers === [],
            verdict: $this->verdict($blockers, $warnings),
            payload: [
                'supplier' => $supplierSummary,
                'source' => $sourceSummary,
                'source_fingerprint' => $sourceFingerprint,
                'expected_state' => $expected,
                'observed_state' => $observed,
                'baseline_lock' => [
                    'matches' => $this->baselineMismatches($expected, $observed) === [],
                    'expected_state_required' => true,
                    'schedule_must_remain_disabled' => true,
                    'record_count_delta' => $recordCountDelta,
                    'record_count_delta_percentage' => $this->percentage($recordCountDelta, max(1, (int) $observed['staged_count'])),
                ],
                'active_import_check' => $activeImport,
                'global_safety_flags' => $flags,
                'semantics_profile' => $semantics->toArray(),
                'selected_semantics_profile' => $semantics->key,
                'official_semantics_profile' => self::OFFICIAL_SEMANTICS_PROFILE,
                'observed_semantics_profile' => self::OBSERVED_SEMANTICS_PROFILE,
                'stock_semantics_discrepancy' => $stockSemanticsDiscrepancy,
                'observed_stock_analysis' => $semantics->usesObservedNumericStockContract()
                    ? (array) data_get($semanticsValidation, 'aggregates.stock', [])
                    : [],
                'reconciliation_continued_despite_stock_semantics_discrepancy' => $reconciliationContinued,
                'unresolved_quantity' => true,
                'unresolved_availability' => true,
                'previous_strict_failure_reference' => self::PREVIOUS_STRICT_FAILURE_REFERENCE,
                'source_profile' => $this->safeSourceProfile($profilePayload),
                'profile_validation' => $profileValidation['summary'],
                'source_aggregates' => $semanticsValidation['aggregates'],
                'staging_aggregates' => $this->stagingAggregates($stagingRows),
                'exact_supplier_sku_reconciliation' => $reconciliation['exact'],
                'normalized_match_diagnostics' => $reconciliation['normalized']['report'],
                'ean_diagnostics' => $reconciliation['ean'],
                'stock_eol_price_policies' => $semanticsValidation['policies'],
                'bounded_hash_samples' => $reconciliation['samples'],
                'sample_hash_namespace' => self::HASH_NAMESPACE,
                'human_review_required' => true,
                'automatic_mapping_or_import_allowed' => false,
                'persisted_feed_profile_created' => false,
                'executable_import_config_created' => false,
                'import_executed' => false,
                'catalog_sync_executed' => false,
                'links_changed' => false,
                'persisted_semantics_profile_created' => false,
                'execution_or_sync_action_created' => false,
                'protected_counts_before' => $protectedCountsBefore,
                'protected_counts_after' => $protectedCountsAfter,
                'protected_state_fingerprints_before' => $protectedFingerprintsBefore,
                'protected_state_fingerprints_after' => $protectedFingerprintsAfter,
                'records_changed' => $recordsChanged,
                'blockers' => $blockers,
                'warnings' => $warnings,
                'issue_counts' => ['blockers' => count($blockers), 'warnings' => count($warnings)],
                'issues' => $this->issues($blockers, $warnings, $this->boundedLimit($options['issue_sample_limit'] ?? 20)),
                'elapsed_seconds' => $this->elapsedSeconds($startedAt),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ],
        );
    }

    /** @param array<string, mixed> $options @return array<string, mixed>|null */
    private function expectedState(array $options): ?array
    {
        $required = ['expected_supplier_id', 'expected_schedule_enabled', 'expected_import_enabled', 'expected_schedule_type', 'expected_staged_count', 'expected_linked_count', 'expected_unlinked_count', 'expected_last_import_at'];

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
            $query->where(fn ($supplier) => $supplier->whereRaw('LOWER(slug) = ?', [$normalized])->orWhereRaw('LOWER(company_name) = ?', [$normalized]));
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

        return [
            'supplier_id' => (int) $supplier->id,
            'schedule_enabled' => (bool) ($supplier->schedule_enabled ?? false),
            'import_enabled' => Schema::hasColumn('suppliers', 'import_enabled') ? (bool) ($supplier->import_enabled ?? false) : null,
            'schedule_type' => Schema::hasColumn('suppliers', 'schedule_type') ? ($supplier->schedule_type ?: null) : null,
            'staged_count' => $stagedCount,
            'linked_count' => $linkedCount,
            'unlinked_count' => $this->supplierProductCount((int) $supplier->id, false),
            'last_import_at' => $this->normalizeDate($supplier->last_import_at ?? null),
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

    /** @return array<int, array{supplier_sku: string, ean: string, linked: bool}> */
    private function stagingRows(int $supplierId): array
    {
        if (! Schema::hasTable('supplier_products') || ! Schema::hasColumn('supplier_products', 'supplier_sku')) {
            throw new RuntimeException('staging_table_unavailable');
        }
        $columns = ['supplier_sku'];
        if (Schema::hasColumn('supplier_products', 'ean')) {
            $columns[] = 'ean';
        }
        if (Schema::hasColumn('supplier_products', 'product_id')) {
            $columns[] = 'product_id';
        }
        $rows = [];
        foreach (DB::table('supplier_products')->where('supplier_id', $supplierId)->select($columns)->orderBy('id')->cursor() as $row) {
            $rows[] = [
                'supplier_sku' => $this->exactSku((string) ($row->supplier_sku ?? '')),
                'ean' => $this->trimNfc((string) ($row->ean ?? '')),
                'linked' => isset($row->product_id) && $row->product_id !== null,
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, string>> */
    private function scanSourceRows(string $source, SupplierSourceFieldSemanticsProfile $semantics): array
    {
        $reader = new XMLReader;
        libxml_use_internal_errors(true);
        if (! $reader->open($source, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            libxml_clear_errors();
            throw new RuntimeException('xml_open_failed');
        }

        $recordPath = $this->comparablePath($semantics->recordPath);
        $wantedPaths = collect($semantics->fieldMap)
            ->flatten()
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->mapWithKeys(fn (string $path): array => [$this->comparablePath($path) => $path])
            ->all();
        $stack = [];
        $recordDepth = null;
        $record = [];
        $records = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                while (count($stack) > $reader->depth) {
                    array_pop($stack);
                }
                if ($stack !== []) {
                    $stack[array_key_last($stack)]['has_child'] = true;
                }
                $name = $reader->localName ?: $reader->name;
                $path = implode('.', [...array_map(fn (array $frame): string => $frame['name'], $stack), $name]);
                $stack[] = ['name' => $name, 'path' => $path, 'has_child' => false, 'text' => ''];
                if ($this->comparablePath($path) === $recordPath) {
                    $recordDepth = count($stack);
                    $record = [];
                }
                if ($reader->isEmptyElement) {
                    $frame = array_pop($stack);
                    $this->captureSourceLeaf($record, $recordDepth, $frame, $semantics->recordPath, $wantedPaths);
                    if ($recordDepth !== null && count($stack) < $recordDepth) {
                        $records[] = $this->normalizeSourceRecord($record);
                        $recordDepth = null;
                        $record = [];
                    }
                }
            } elseif (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE], true) && $stack !== []) {
                $index = array_key_last($stack);
                $stack[$index]['text'] .= (string) $reader->value;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $stack !== []) {
                $frame = array_pop($stack);
                if (! $frame['has_child']) {
                    $this->captureSourceLeaf($record, $recordDepth, $frame, $semantics->recordPath, $wantedPaths);
                }
                if ($recordDepth !== null && count($stack) < $recordDepth) {
                    $records[] = $this->normalizeSourceRecord($record);
                    $recordDepth = null;
                    $record = [];
                }
            }
        }

        $errors = libxml_get_errors();
        libxml_clear_errors();
        $reader->close();
        if ($errors !== []) {
            throw new RuntimeException('malformed_xml');
        }

        return $records;
    }

    /** @param array<string, string> $record @param array<string, mixed> $frame @param array<string, string> $wantedPaths */
    private function captureSourceLeaf(array &$record, ?int $recordDepth, array $frame, string $recordPath, array $wantedPaths): void
    {
        if ($recordDepth === null || ! str_starts_with($this->comparablePath((string) $frame['path']), $this->comparablePath($recordPath).'.')) {
            return;
        }
        $relative = $this->comparablePath((string) str_ireplace(rtrim($recordPath, '.').'.', '', (string) $frame['path']));
        if (! array_key_exists($relative, $wantedPaths)) {
            return;
        }
        $record[$relative] = $this->trimNfc((string) ($frame['text'] ?? ''));
    }

    /** @param array<string, string> $record @return array<string, string> */
    private function normalizeSourceRecord(array $record): array
    {
        $record['_stock_present'] = array_key_exists('stock', $record) ? '1' : '0';
        foreach (['partno', 'ean', 'stock', 'eol', 'dac_price', 'fd_price', 'promo', 'news', 'greentax'] as $path) {
            $record[$path] ??= '';
        }

        return $record;
    }

    /** @param array<string, mixed> $profile @return array{blockers: array<int, string>, warnings: array<int, string>, summary: array<string, mixed>} */
    private function validateProfileFields(array $profile, SupplierSourceFieldSemanticsProfile $semantics): array
    {
        $fields = collect((array) data_get($profile, 'field_inventory.fields', []))
            ->mapWithKeys(fn (mixed $field, string $path): array => [$this->comparablePath($path) => $field])
            ->all();
        $missing = [];
        foreach ($semantics->requiredFields as $path) {
            if (! array_key_exists($this->comparablePath($path), $fields)) {
                $missing[] = $path;
            }
        }
        $warnings = [];
        if (! array_key_exists('greentax', $fields)) {
            $warnings[] = 'documented_greentax_absent_in_current_snapshot';
        }

        return [
            'blockers' => $missing === [] ? [] : ['official_semantics_required_field_missing'],
            'warnings' => $warnings,
            'summary' => [
                'record_path_matches_official_profile' => $this->comparablePath((string) data_get($profile, 'parser_result.selected_record_path')) === $this->comparablePath($semantics->recordPath),
                'record_path_matches_selected_profile' => $this->comparablePath((string) data_get($profile, 'parser_result.selected_record_path')) === $this->comparablePath($semantics->recordPath),
                'missing_required_field_count' => count($missing),
                'missing_required_field_hashes' => array_map(fn (string $path): string => $this->hashSample('missing_required_field', $path), $missing),
                'documented_greentax_present' => array_key_exists('greentax', $fields),
                'generic_role_inference_not_used_for_official_semantics' => true,
            ],
        ];
    }

    /** @param array<int, array<string, string>> $rows @return array{blockers: array<int, string>, warnings: array<int, string>, aggregates: array<string, mixed>, policies: array<string, mixed>} */
    private function validateSourceSemantics(array $rows, SupplierSourceFieldSemanticsProfile $semantics): array
    {
        $skuGroups = [];
        $blankSku = 0;
        $blankEan = 0;
        $stock = [
            'total_records' => count($rows),
            'elements_present' => 0,
            'blank_count' => 0,
            'numeric_count' => 0,
            'non_numeric_count' => 0,
            'integer_count' => 0,
            'fractional_count' => 0,
            'negative_count' => 0,
            'zero_count' => 0,
            'one_count' => 0,
            'greater_than_one_count' => 0,
            'positive_count' => 0,
            'distinct_numeric_value_count' => 0,
            'minimum_numeric_value' => null,
            'maximum_numeric_value' => null,
            'official_binary_semantics_match' => false,
            'observed_numeric_contract_valid' => false,
            'non_binary_count' => 0,
        ];
        $eol = ['zero_count' => 0, 'one_count' => 0, 'invalid_count' => 0];
        $prices = ['dac_price' => $this->priceCounters(), 'fd_price' => $this->priceCounters(), 'both_numeric_count' => 0, 'equal_count' => 0, 'dac_higher_count' => 0, 'dac_lower_count' => 0];
        $numericStockValues = [];
        $distinctStockValues = [];
        $stockEolCombinations = [
            'stock_zero_eol_zero' => 0,
            'stock_one_eol_zero' => 0,
            'stock_greater_than_one_eol_zero' => 0,
            'stock_zero_eol_one' => 0,
            'stock_one_eol_one' => 0,
            'stock_greater_than_one_eol_one' => 0,
        ];

        foreach ($rows as $row) {
            $sku = $this->exactSku($row['partno']);
            if ($sku === '') {
                $blankSku++;
            } else {
                $skuGroups[$sku] = ($skuGroups[$sku] ?? 0) + 1;
            }
            if ($this->trimNfc($row['ean']) === '') {
                $blankEan++;
            }

            if (($row['_stock_present'] ?? '0') === '1') {
                $stock['elements_present']++;
            }
            $stockRaw = $this->trimNfc($row['stock']);
            $stockValue = $this->decimalValue($stockRaw);
            $validObservedStock = false;
            if ($stockRaw === '') {
                $stock['blank_count']++;
                $stock['non_binary_count']++;
            } elseif ($stockValue === null) {
                $stock['non_numeric_count']++;
                $stock['non_binary_count']++;
            } else {
                $stock['numeric_count']++;
                $numericStockValues[] = $stockValue;
                $distinctStockValues[$this->canonicalNumericKey($stockValue)] = true;

                if (floor($stockValue) !== $stockValue) {
                    $stock['fractional_count']++;
                } elseif ($stockValue < 0) {
                    $stock['negative_count']++;
                } else {
                    $stock['integer_count']++;
                    $validObservedStock = true;
                }

                if ($stockValue == 0.0) {
                    $stock['zero_count']++;
                } elseif ($stockValue > 0) {
                    $stock['positive_count']++;
                }
                if ($stockValue == 1.0) {
                    $stock['one_count']++;
                } elseif ($stockValue > 1.0) {
                    $stock['greater_than_one_count']++;
                }
                if (! in_array($stockValue, [0.0, 1.0], true)) {
                    $stock['non_binary_count']++;
                }
            }

            $eolValue = $this->decimalValue($row['eol']);
            if ($eolValue === 0.0) {
                $eol['zero_count']++;
            } elseif ($eolValue === 1.0) {
                $eol['one_count']++;
            } else {
                $eol['invalid_count']++;
            }

            if ($validObservedStock && in_array($eolValue, [0.0, 1.0], true)) {
                $stockBand = $stockValue === 0.0 ? 'zero' : ($stockValue === 1.0 ? 'one' : 'greater_than_one');
                $eolBand = $eolValue === 0.0 ? 'zero' : 'one';
                $stockEolCombinations['stock_'.$stockBand.'_eol_'.$eolBand]++;
            }

            $dac = $this->countPrice($prices['dac_price'], $row['dac_price']);
            $fd = $this->countPrice($prices['fd_price'], $row['fd_price']);
            if ($dac !== null && $fd !== null) {
                $prices['both_numeric_count']++;
                if ($dac === $fd) {
                    $prices['equal_count']++;
                } elseif ($dac > $fd) {
                    $prices['dac_higher_count']++;
                } else {
                    $prices['dac_lower_count']++;
                }
            }
        }

        if ($numericStockValues !== []) {
            sort($numericStockValues, SORT_NUMERIC);
            $stock['minimum_numeric_value'] = $numericStockValues[0];
            $stock['maximum_numeric_value'] = $numericStockValues[array_key_last($numericStockValues)];
        }
        $stock['distinct_numeric_value_count'] = count($distinctStockValues);
        $stock['official_binary_semantics_match'] = $stock['non_binary_count'] === 0;
        $stock['observed_numeric_contract_valid'] = $stock['elements_present'] === count($rows)
            && $stock['blank_count'] === 0
            && $stock['non_numeric_count'] === 0
            && $stock['fractional_count'] === 0
            && $stock['negative_count'] === 0;

        $duplicateGroups = array_filter($skuGroups, fn (int $count): bool => $count > 1);
        $blockers = [];
        if ($blankSku > 0) {
            $blockers[] = 'blank_authoritative_supplier_sku_detected';
        }
        if ($duplicateGroups !== []) {
            $blockers[] = 'duplicate_authoritative_supplier_sku_detected';
        }
        if ($semantics->usesObservedNumericStockContract() && ! $stock['observed_numeric_contract_valid']) {
            $blockers[] = 'invalid_observed_stock_semantics_detected';
        }
        if (! $semantics->usesObservedNumericStockContract() && ! $stock['official_binary_semantics_match']) {
            $blockers[] = 'invalid_stock_semantics_detected';
        }
        if ($eol['invalid_count'] > 0) {
            $blockers[] = 'invalid_eol_semantics_detected';
        }
        if ($prices['dac_price']['negative_count'] > 0 || $prices['fd_price']['negative_count'] > 0 || $prices['dac_price']['non_numeric_count'] > 0 || $prices['fd_price']['non_numeric_count'] > 0) {
            $blockers[] = 'invalid_price_candidate_detected';
        }
        $warnings = [];
        if ($blankEan > 0) {
            $warnings[] = 'blank_ean_requires_review';
        }
        if ($prices['dac_price']['zero_count'] > 0 || $prices['fd_price']['zero_count'] > 0) {
            $warnings[] = 'zero_price_candidate_requires_review';
        }
        if ($eol['one_count'] > 0) {
            $warnings[] = 'eol_rows_require_human_review';
        }
        if ($semantics->usesObservedNumericStockContract()) {
            $warnings[] = 'stock_semantics_discrepancy_requires_review';
        }

        $policies = [
            'stock_is_not_quantity' => true,
            'price_selection_resolved' => false,
            'currency_inferred' => false,
            'vat_inferred' => false,
            'greentax_inferred' => false,
            'no_auto_deactivate_delete_unpublish_link_or_catalog_write' => true,
        ];
        if ($semantics->usesObservedNumericStockContract()) {
            $policies += [
                'stock_semantic_status' => 'unresolved_numeric',
                'stock_is_not_binary_availability' => true,
                'quantity_resolved' => false,
                'availability_resolved' => false,
                'automatic_quantity_mapping_allowed' => false,
                'automatic_availability_mapping_allowed' => false,
                'stock_eol_combinations_review_only' => true,
            ];
        } else {
            $policies += [
                'stock_zero_eol_zero' => 'candidate_out_of_stock_requires_human_review',
                'stock_positive_eol_zero' => 'candidate_in_stock_requires_human_review',
                'eol_one' => 'eol_review_required',
            ];
        }

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'observed_numeric_contract_valid' => $stock['observed_numeric_contract_valid'],
            'aggregates' => [
                'source_record_count' => count($rows),
                'supplier_sku' => [
                    'non_blank_count' => count($rows) - $blankSku,
                    'blank_count' => $blankSku,
                    'exact_unique_count' => count($skuGroups),
                    'exact_duplicate_group_count' => count($duplicateGroups),
                    'exact_duplicate_row_count' => array_sum($duplicateGroups),
                ],
                'ean' => ['blank_count' => $blankEan, 'non_blank_count' => count($rows) - $blankEan],
                'stock' => $stock,
                'eol' => $eol,
                'stock_eol_combinations' => $stockEolCombinations,
                'price_candidates' => $prices,
            ],
            'policies' => $policies,
        ];
    }

    /** @param array<int, array<string, string>> $sourceRows @param array<int, array{supplier_sku: string, ean: string, linked: bool}> $stagingRows @return array{exact: array<string, mixed>, normalized: array<string, mixed>, ean: array<string, mixed>, samples: array<string, mixed>, warnings: array<int, string>} */
    private function reconcileRows(array $sourceRows, array $stagingRows, int $sampleLimit): array
    {
        $source = $this->indexSourceRows($sourceRows);
        $staging = $this->indexStagingRows($stagingRows);
        $sourceKeys = array_keys($source);
        $stagingKeys = array_keys($staging);
        sort($sourceKeys, SORT_STRING);
        sort($stagingKeys, SORT_STRING);
        $sourceOnly = array_values(array_diff($sourceKeys, $stagingKeys));
        $stagingOnly = array_values(array_diff($stagingKeys, $sourceKeys));
        $oneToMany = 0;
        $manyToOne = 0;
        $oneToOne = [];
        $linkedMatched = 0;
        $unlinkedMatched = 0;
        $stagingOnlyLinked = 0;
        $stagingOnlyUnlinked = 0;

        foreach ($stagingOnly as $sku) {
            foreach ($staging[$sku] as $row) {
                if ($row['linked']) {
                    $stagingOnlyLinked++;
                } else {
                    $stagingOnlyUnlinked++;
                }
            }
        }

        foreach (array_values(array_intersect($sourceKeys, $stagingKeys)) as $sku) {
            $sourceCount = count($source[$sku]);
            $stagingCount = count($staging[$sku]);
            if ($sourceCount > 1 && $stagingCount === 1) {
                $manyToOne++;
            }
            if ($sourceCount === 1 && $stagingCount > 1) {
                $oneToMany++;
            }
            if ($sourceCount === 1 && $stagingCount === 1) {
                $oneToOne[$sku] = [$source[$sku][0], $staging[$sku][0]];
                $staging[$sku][0]['linked'] ? $linkedMatched++ : $unlinkedMatched++;
            }
        }

        $normalized = $this->normalizedDiagnostics($sourceOnly, $stagingOnly, $source, $staging);
        $ean = $this->eanDiagnostics($oneToOne, $sourceOnly, $source, $staging, $sampleLimit);
        $warnings = [];
        if ($sourceOnly !== [] || $stagingOnly !== []) {
            $warnings[] = 'exact_supplier_sku_reconciliation_requires_review';
        }
        if ($oneToMany > 0 || $manyToOne > 0 || $normalized['report']['ambiguous_normalized_candidate_count'] > 0) {
            $warnings[] = 'identifier_collision_risk_requires_review';
        }
        if ($ean['cross_sku_ean_conflict_count'] > 0 || $ean['ean_differs_on_exact_sku_count'] > 0) {
            $warnings[] = 'ean_diagnostics_require_review';
        }

        return [
            'exact' => [
                'authoritative_match_rule' => 'exact_normalized_safe_source_partno_to_staging_supplier_sku',
                'source_unique_sku_count' => count($sourceKeys),
                'staging_unique_sku_count' => count($stagingKeys),
                'exact_one_to_one_match_count' => count($oneToOne),
                'source_only_sku_count' => count($sourceOnly),
                'staging_only_sku_count' => count($stagingOnly),
                'staging_only_linked_row_count' => $stagingOnlyLinked,
                'staging_only_unlinked_row_count' => $stagingOnlyUnlinked,
                'one_source_to_many_staging_risk_count' => $oneToMany,
                'many_source_to_one_staging_risk_count' => $manyToOne,
                'matched_linked_staging_row_count' => $linkedMatched,
                'matched_unlinked_staging_row_count' => $unlinkedMatched,
                'source_balance_valid' => count($sourceKeys) === count($oneToOne) + count($sourceOnly) + $manyToOne,
                'staging_balance_valid' => count($stagingKeys) === count($oneToOne) + count($stagingOnly) + $oneToMany,
                'automatic_link_merge_or_repair_allowed' => false,
            ],
            'normalized' => $normalized,
            'ean' => $ean,
            'samples' => [
                'limit' => $sampleLimit,
                'source_only_supplier_sku_hashes' => $this->hashedSamples('source_only_supplier_sku', $sourceOnly, $sampleLimit),
                'staging_only_supplier_sku_hashes' => $this->hashedSamples('staging_only_supplier_sku', $stagingOnly, $sampleLimit),
                'normalized_only_supplier_sku_hashes' => $this->hashedSamples('normalized_only_supplier_sku', $normalized['raw_keys'], $sampleLimit),
                'cross_sku_ean_conflict_hashes' => $ean['cross_sku_ean_conflict_hashes'],
                'truncation' => [
                    'source_only_supplier_sku' => count($sourceOnly) > $sampleLimit,
                    'staging_only_supplier_sku' => count($stagingOnly) > $sampleLimit,
                    'normalized_only_supplier_sku' => count($normalized['raw_keys']) > $sampleLimit,
                    'cross_sku_ean_conflict' => (int) $ean['cross_sku_ean_conflict_count'] > $sampleLimit,
                ],
            ],
            'warnings' => $warnings,
        ];
    }

    /** @param array<int, array<string, string>> $rows @return array<string, array<int, array<string, string>>> */
    private function indexSourceRows(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $sku = $this->exactSku($row['partno']);
            if ($sku !== '') {
                $index[$sku][] = $row;
            }
        }
        ksort($index);

        return $index;
    }

    /** @param array<int, array{supplier_sku: string, ean: string, linked: bool}> $rows @return array<string, array<int, array{supplier_sku: string, ean: string, linked: bool}>> */
    private function indexStagingRows(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            if ($row['supplier_sku'] !== '') {
                $index[$row['supplier_sku']][] = $row;
            }
        }
        ksort($index);

        return $index;
    }

    /** @param array<int, string> $sourceOnly @param array<int, string> $stagingOnly @param array<string, array<int, array<string, string>>> $source @param array<string, array<int, array{supplier_sku: string, ean: string, linked: bool}>> $staging @return array{report: array<string, mixed>, raw_keys: array<int, string>} */
    private function normalizedDiagnostics(array $sourceOnly, array $stagingOnly, array $source, array $staging): array
    {
        $sourceNormalized = [];
        foreach ($sourceOnly as $sku) {
            $sourceNormalized[$this->normalizedSku($sku)][] = $sku;
        }
        $stagingNormalized = [];
        foreach ($stagingOnly as $sku) {
            $stagingNormalized[$this->normalizedSku($sku)][] = $sku;
        }
        $candidate = 0;
        $ambiguous = 0;
        $rawKeys = [];
        foreach (array_intersect(array_keys($sourceNormalized), array_keys($stagingNormalized)) as $normalized) {
            if (count($sourceNormalized[$normalized]) === 1 && count($stagingNormalized[$normalized]) === 1) {
                $candidate++;
                $rawKeys[] = $sourceNormalized[$normalized][0];
            } else {
                $ambiguous++;
            }
        }

        return [
            'report' => [
                'normalization_is_diagnostic_only' => true,
                'case_whitespace_nfc_normalized_only_candidate_count' => $candidate,
                'ambiguous_normalized_candidate_count' => $ambiguous,
                'automatic_normalized_match_allowed' => false,
            ],
            'raw_keys' => $rawKeys,
        ];
    }

    /** @param array<string, array{0: array<string, string>, 1: array{supplier_sku: string, ean: string, linked: bool}}> $oneToOne @param array<int, string> $sourceOnly @param array<string, array<int, array<string, string>>> $source @param array<string, array<int, array{supplier_sku: string, ean: string, linked: bool}>> $staging @return array<string, mixed> */
    private function eanDiagnostics(array $oneToOne, array $sourceOnly, array $source, array $staging, int $sampleLimit): array
    {
        $matches = ['equal_count' => 0, 'differs_count' => 0, 'source_blank_count' => 0, 'staging_blank_count' => 0, 'both_blank_count' => 0];
        foreach ($oneToOne as [$sourceRow, $stagingRow]) {
            $sourceEan = $this->trimNfc($sourceRow['ean']);
            $stagingEan = $this->trimNfc($stagingRow['ean']);
            if ($sourceEan === '' && $stagingEan === '') {
                $matches['both_blank_count']++;
            } elseif ($sourceEan === '') {
                $matches['source_blank_count']++;
            } elseif ($stagingEan === '') {
                $matches['staging_blank_count']++;
            } elseif ($sourceEan === $stagingEan) {
                $matches['equal_count']++;
            } else {
                $matches['differs_count']++;
            }
        }
        $stagingEans = [];
        foreach ($staging as $sku => $rows) {
            foreach ($rows as $row) {
                if ($row['ean'] !== '') {
                    $stagingEans[$row['ean']][$sku] = true;
                }
            }
        }
        $conflictKeys = [];
        foreach ($sourceOnly as $sku) {
            foreach ($source[$sku] as $row) {
                $ean = $this->trimNfc($row['ean']);
                if ($ean !== '' && isset($stagingEans[$ean])) {
                    $conflictKeys[] = $this->hashSample('cross_sku_ean_conflict', $sku.'|'.$ean);
                }
            }
        }
        sort($conflictKeys, SORT_STRING);

        return [
            'ean_is_diagnostic_only' => true,
            'ean_equal_on_exact_sku_count' => $matches['equal_count'],
            'ean_differs_on_exact_sku_count' => $matches['differs_count'],
            'source_blank_ean_on_exact_sku_count' => $matches['source_blank_count'],
            'staging_blank_ean_on_exact_sku_count' => $matches['staging_blank_count'],
            'both_blank_ean_on_exact_sku_count' => $matches['both_blank_count'],
            'cross_sku_ean_conflict_count' => count($conflictKeys),
            'cross_sku_ean_conflict_hashes' => array_slice(array_values(array_unique($conflictKeys)), 0, $sampleLimit),
        ];
    }

    /** @param array<int, array{supplier_sku: string, ean: string, linked: bool}> $rows @return array<string, int> */
    private function stagingAggregates(array $rows): array
    {
        $blankSku = 0;
        $blankEan = 0;
        $linked = 0;
        foreach ($rows as $row) {
            $blankSku += $row['supplier_sku'] === '' ? 1 : 0;
            $blankEan += $row['ean'] === '' ? 1 : 0;
            $linked += $row['linked'] ? 1 : 0;
        }

        return [
            'row_count' => count($rows),
            'blank_supplier_sku_count' => $blankSku,
            'blank_ean_count' => $blankEan,
            'linked_row_count' => $linked,
            'unlinked_row_count' => count($rows) - $linked,
        ];
    }

    /** @return array<string, int> */
    private function priceCounters(): array
    {
        return ['numeric_count' => 0, 'zero_count' => 0, 'positive_count' => 0, 'negative_count' => 0, 'non_numeric_count' => 0];
    }

    /** @param array<string, int> $counter */
    private function countPrice(array &$counter, string $value): ?float
    {
        $decimal = $this->decimalValue($value);
        if ($decimal === null) {
            $counter['non_numeric_count']++;

            return null;
        }
        $counter['numeric_count']++;
        if ($decimal < 0) {
            $counter['negative_count']++;
        } elseif ($decimal == 0.0) {
            $counter['zero_count']++;
        } else {
            $counter['positive_count']++;
        }

        return $decimal;
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
        $hash = hash_init('sha256');
        hash_update($hash, $table."\n");
        $query = DB::table($table)->select($columns);
        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }
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

    /** @param array<string, int> $before @param array<string, int> $after @return array<string, int> */
    private function recordsChanged(array $before, array $after): array
    {
        $changed = [];
        foreach ($before as $table => $count) {
            $changed[$table] = abs((int) ($after[$table] ?? 0) - $count);
        }

        return $changed;
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

    /** @return array<string, mixed> */
    private function safeSourceProfile(array $profile): array
    {
        return [
            'schema_version' => $profile['schema_version'] ?? null,
            'verdict' => $profile['verdict'] ?? null,
            'parser_result' => $profile['parser_result'] ?? [],
            'record_path_analysis' => $profile['record_path_analysis'] ?? [],
            'records_changed' => $profile['records_changed'] ?? $this->zeroRecordsChanged(),
        ];
    }

    /** @param array<int, string> $blockers @param array<int, string> $warnings */
    private function failure(string $verdict, array $blockers, float $startedAt, array $supplier = [], array $source = [], array $sourceFingerprint = [], ?array $expectedState = null, array $observedState = [], array $activeImportCheck = [], array $globalSafetyFlags = [], array $semanticsProfile = [], array $sourceProfile = [], array $protectedCountsBefore = [], array $protectedCountsAfter = [], array $protectedFingerprintsBefore = [], array $protectedFingerprintsAfter = []): LocalSupplierSourceStagingReconciliationReport
    {
        $selectedProfile = $semanticsProfile['key'] ?? null;
        $isObservedProfile = $selectedProfile === self::OBSERVED_SEMANTICS_PROFILE;

        return $this->report(false, $verdict, [
            'supplier' => $supplier,
            'source' => $source,
            'source_fingerprint' => $sourceFingerprint,
            'expected_state' => $expectedState ?? [],
            'observed_state' => $observedState,
            'baseline_lock' => ['matches' => false, 'expected_state_required' => true, 'schedule_must_remain_disabled' => true],
            'active_import_check' => $activeImportCheck,
            'global_safety_flags' => $globalSafetyFlags,
            'semantics_profile' => $semanticsProfile,
            'selected_semantics_profile' => $selectedProfile,
            'official_semantics_profile' => self::OFFICIAL_SEMANTICS_PROFILE,
            'observed_semantics_profile' => self::OBSERVED_SEMANTICS_PROFILE,
            'stock_semantics_discrepancy' => [
                'detected' => $isObservedProfile,
                'official_claim' => $semanticsProfile['official_stock_claim'] ?? null,
                'observed_contract' => $semanticsProfile['observed_stock_contract'] ?? null,
                'semantic_resolution' => $semanticsProfile['semantic_resolution'] ?? 'unresolved',
                'quantity_mapping_allowed' => false,
                'availability_mapping_allowed' => false,
                'requires_human_review' => true,
                'reconciliation_blocked' => $isObservedProfile ? false : null,
            ],
            'observed_stock_analysis' => [],
            'reconciliation_continued_despite_stock_semantics_discrepancy' => false,
            'unresolved_quantity' => true,
            'unresolved_availability' => true,
            'previous_strict_failure_reference' => self::PREVIOUS_STRICT_FAILURE_REFERENCE,
            'source_profile' => $sourceProfile,
            'human_review_required' => true,
            'automatic_mapping_or_import_allowed' => false,
            'persisted_feed_profile_created' => false,
            'executable_import_config_created' => false,
            'import_executed' => false,
            'catalog_sync_executed' => false,
            'links_changed' => false,
            'persisted_semantics_profile_created' => false,
            'execution_or_sync_action_created' => false,
            'protected_counts_before' => $protectedCountsBefore,
            'protected_counts_after' => $protectedCountsAfter,
            'protected_state_fingerprints_before' => $protectedFingerprintsBefore,
            'protected_state_fingerprints_after' => $protectedFingerprintsAfter,
            'records_changed' => $this->zeroRecordsChanged(),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => [],
            'issue_counts' => ['blockers' => count(array_unique($blockers)), 'warnings' => 0],
            'issues' => $this->issues($blockers, [], 20),
            'elapsed_seconds' => $this->elapsedSeconds($startedAt),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function report(bool $success, string $verdict, array $payload): LocalSupplierSourceStagingReconciliationReport
    {
        return new LocalSupplierSourceStagingReconciliationReport($success, $verdict, $payload);
    }

    /** @return array<string, int> */
    private function zeroRecordsChanged(): array
    {
        return array_fill_keys([...self::PROTECTED_TABLES, 'catalog_sync'], 0);
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

    /** @param array<int, string> $blockers @param array<int, string> $warnings */
    private function verdict(array $blockers, array $warnings): string
    {
        if ($blockers !== []) {
            return 'audit_failed';
        }

        if (in_array('stock_semantics_discrepancy_requires_review', $warnings, true)) {
            return 'reconciliation_requires_stock_semantics_review';
        }

        return $warnings === [] ? 'reconciliation_ready_for_human_review' : 'reconciliation_requires_human_review';
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

    /** @param array<int, string> $values @return array<int, string> */
    private function hashedSamples(string $bucket, array $values, int $limit): array
    {
        sort($values, SORT_STRING);

        return array_slice(array_map(fn (string $value): string => $this->hashSample($bucket, $value), array_values(array_unique($values))), 0, $limit);
    }

    private function hashSample(string $bucket, string $value): string
    {
        return hash('sha256', self::HASH_NAMESPACE.'|'.$bucket.'|'.$value);
    }

    /** @param array<string, mixed> $stock @return array<string, mixed> */
    private function stockSemanticsDiscrepancy(SupplierSourceFieldSemanticsProfile $semantics, array $stock, bool $reconciliationContinued): array
    {
        $evidence = [];
        foreach ([
            'total_records', 'elements_present', 'blank_count', 'numeric_count', 'non_numeric_count', 'integer_count',
            'fractional_count', 'negative_count', 'zero_count', 'one_count', 'greater_than_one_count', 'positive_count',
            'distinct_numeric_value_count', 'minimum_numeric_value', 'maximum_numeric_value',
        ] as $key) {
            $evidence[$key] = $stock[$key] ?? null;
        }

        return [
            'detected' => (bool) ($semantics->stockSemantics['semantics_discrepancy'] ?? false),
            'official_claim' => $semantics->stockSemantics['official_stock_claim'] ?? null,
            'observed_contract' => $semantics->stockSemantics['observed_stock_contract'] ?? null,
            'official_binary_semantics_match' => (bool) ($stock['official_binary_semantics_match'] ?? false),
            'observed_numeric_contract_valid' => (bool) ($stock['observed_numeric_contract_valid'] ?? false),
            'semantic_resolution' => $semantics->stockSemantics['semantic_resolution'] ?? 'unresolved',
            'quantity_mapping_allowed' => (bool) ($semantics->stockSemantics['automatic_quantity_mapping_allowed'] ?? false),
            'availability_mapping_allowed' => (bool) ($semantics->stockSemantics['automatic_availability_mapping_allowed'] ?? false),
            'requires_human_review' => true,
            'reconciliation_blocked' => $semantics->usesObservedNumericStockContract() ? false : ! (bool) ($stock['official_binary_semantics_match'] ?? false),
            'reconciliation_continued' => $reconciliationContinued,
            'evidence' => $evidence,
        ];
    }

    private function exactSku(string $value): string
    {
        return $this->trimNfc($value);
    }

    private function normalizedSku(string $value): string
    {
        $value = $this->trimNfc($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::lower($value);
    }

    private function trimNfc(string $value): string
    {
        $value = trim((string) (preg_replace('/^\s+|\s+$/u', '', $value) ?? $value));
        if (class_exists('Normalizer')) {
            /** @var class-string $normalizer */
            $normalizer = 'Normalizer';
            $value = $normalizer::normalize($value, $normalizer::FORM_C) ?: $value;
        }

        return $value;
    }

    private function decimalValue(string $value): ?float
    {
        $value = str_replace(',', '.', $this->trimNfc($value));

        return is_numeric($value) ? (float) $value : null;
    }

    private function canonicalNumericKey(float $value): string
    {
        return rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.') ?: '0';
    }

    private function comparablePath(string $path): string
    {
        return Str::lower(trim(str_replace('/', '.', $path), '.'));
    }

    private function normalizePath(mixed $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        $path = trim(str_replace('/', '.', (string) $path), '.');

        return $path === '' ? null : $path;
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

    private function boundedLimit(mixed $value): int
    {
        return max(1, min(20, (int) $value ?: 20));
    }

    private function elapsedSeconds(float $startedAt): float
    {
        return round(max(0.0001, microtime(true) - $startedAt), 6);
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
}
