<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ControlledAsbisDualFeedStagingImportService
{
    private const MAX_BATCH_SIZE = 1000;

    private const WRITE_SCOPE = 'supplier_products_only';

    private const SUPPLIER_KEY = 'asbis';

    private mixed $expectedReadyCount = null;

    private mixed $expectedStagedCount = null;

    private int $existingConflictCount = 0;

    public function __construct(
        private readonly AsbisApplyReadinessAuditService $audit,
        private readonly AsbisCandidateFingerprintService $fingerprint,
        private readonly SupplierImportExecutionLock $executionLock,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $startedAt = microtime(true);
        $apply = (bool) ($options['apply'] ?? false);
        $this->expectedReadyCount = $options['expected_ready_count'] ?? null;
        $this->expectedStagedCount = $options['expected_asbis_staged_count'] ?? null;
        $this->existingConflictCount = 0;
        $batchSize = $this->batchSize($options['batch_size'] ?? 500);
        $sampleLimit = max(0, min((int) ($options['sample_limit'] ?? 20), 100));
        $audit = $this->audit->run([
            ...$options,
            'mode' => 'controlled_asbis_dual_feed_staging_import',
            'full_file' => true,
            'include_candidate_payloads' => true,
        ]);
        $mode = $apply ? 'apply' : 'dry_run';

        if (! ($audit['success'] ?? false)) {
            return $this->result(
                $audit,
                $mode,
                false,
                false,
                $this->auditIssues($audit),
                [],
                0,
                0,
                0,
                0,
                $batchSize,
                $startedAt
            );
        }

        $candidatePayloads = $audit['candidate_payloads'] ?? [];
        $sourceFingerprints = $audit['source_fingerprints'] ?? [];
        $supplierId = (int) data_get($audit, 'supplier.id', 0);
        $supplier = $supplierId > 0 ? Supplier::query()->find($supplierId) : null;

        if (! $supplier instanceof Supplier || $this->supplierKey($supplier) !== self::SUPPLIER_KEY) {
            return $this->result(
                $audit,
                $mode,
                false,
                false,
                ['supplier_must_be_asbis'],
                $candidatePayloads,
                0,
                0,
                0,
                0,
                $batchSize,
                $startedAt
            );
        }

        $currentCount = $this->supplierProductCount($supplier);
        $conflicts = $this->existingSkuConflicts($supplier, $candidatePayloads);
        $this->existingConflictCount = count($conflicts);
        $preflightReasons = $this->preflightReasons(
            $audit,
            $supplier,
            $sourceFingerprints,
            $candidatePayloads,
            $currentCount,
            $conflicts,
            $options,
            $apply
        );
        $dryRunReasons = array_values(array_unique([
            ...$preflightReasons,
            ...($apply ? [] : ['dry_run_mode']),
        ]));

        if (! $apply) {
            return $this->result(
                $audit,
                $mode,
                true,
                false,
                $dryRunReasons,
                $candidatePayloads,
                $currentCount,
                $currentCount,
                0,
                0,
                $batchSize,
                $startedAt,
                $sampleLimit
            );
        }

        if ($preflightReasons !== []) {
            return $this->result(
                $audit,
                $mode,
                false,
                false,
                $preflightReasons,
                $candidatePayloads,
                $currentCount,
                $currentCount,
                0,
                0,
                $batchSize,
                $startedAt,
                $sampleLimit
            );
        }

        if (! $this->executionLock->acquire($supplier)) {
            return $this->result(
                $audit,
                $mode,
                false,
                false,
                ['concurrent_import_lock_failed'],
                $candidatePayloads,
                $currentCount,
                $currentCount,
                0,
                0,
                $batchSize,
                $startedAt,
                $sampleLimit
            );
        }

        try {
            $finalFingerprints = $this->sourceFingerprints($audit);

            if (! $this->sameSourceFingerprints($sourceFingerprints, $finalFingerprints)) {
                return $this->result(
                    $audit,
                    $mode,
                    false,
                    false,
                    ['source_changed_during_preflight'],
                    $candidatePayloads,
                    $currentCount,
                    $currentCount,
                    0,
                    0,
                    $batchSize,
                    $startedAt,
                    $sampleLimit
                );
            }

            $transaction = DB::transaction(function () use ($supplier, $candidatePayloads, $batchSize, $currentCount): array {
                $lockedSupplier = Supplier::query()->whereKey($supplier->getKey())->lockForUpdate()->first();

                if (! $lockedSupplier instanceof Supplier) {
                    throw new ControlledAsbisStagingApplyException('transaction_failed', 'The ASBIS supplier row could not be locked.');
                }

                $lockedCount = $this->supplierProductCount($lockedSupplier);

                if ($lockedCount !== $currentCount || $lockedCount > 0) {
                    throw new ControlledAsbisStagingApplyException(
                        $lockedCount > 0 ? 'existing_asbis_staging_conflict' : 'expected_asbis_staged_count_mismatch',
                        'The ASBIS staging count changed before the transaction began.'
                    );
                }

                $lockedConflicts = $this->existingSkuConflicts($lockedSupplier, $candidatePayloads);

                if ($lockedConflicts !== []) {
                    throw new ControlledAsbisStagingApplyException(
                        'existing_asbis_staging_conflict',
                        'One or more ASBIS supplier SKUs already exist in staging.'
                    );
                }

                $protectedBefore = $this->protectedCounts();
                $attempted = count($candidatePayloads);
                $inserted = 0;
                $batches = 0;

                foreach (array_chunk($candidatePayloads, $batchSize) as $payloadBatch) {
                    $rows = array_map(function (array $payload): array {
                        $attributes = $payload;
                        $attributes['raw_data'] = json_encode($attributes['raw_data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $attributes['received_at'] = now();
                        $attributes['created_at'] = now();
                        $attributes['updated_at'] = now();

                        return $attributes;
                    }, $payloadBatch);

                    if ($rows !== []) {
                        SupplierProduct::query()->insert($rows);
                        $inserted += count($rows);
                        $batches++;
                    }
                }

                $afterCount = $this->supplierProductCount($lockedSupplier);

                if ($inserted !== $attempted || $afterCount !== $currentCount + $inserted) {
                    throw new ControlledAsbisStagingApplyException(
                        'post_insert_verification_failed',
                        'ASBIS supplier staging count did not match the inserted candidate count.'
                    );
                }

                $this->verifyInsertedSkus($lockedSupplier, $candidatePayloads);
                $protectedAfter = $this->protectedCounts();

                if ($protectedBefore !== $protectedAfter) {
                    throw new ControlledAsbisStagingApplyException(
                        'post_insert_verification_failed',
                        'A protected table changed during ASBIS staging apply.'
                    );
                }

                return [
                    'staged_before' => $currentCount,
                    'staged_after' => $afterCount,
                    'attempted' => $attempted,
                    'inserted' => $inserted,
                    'batches' => $batches,
                    'protected_records' => $this->zeroProtectedRecords($protectedBefore),
                ];
            }, 3);

            return $this->result(
                $audit,
                $mode,
                true,
                true,
                [],
                $candidatePayloads,
                $transaction['staged_before'],
                $transaction['staged_after'],
                $transaction['attempted'],
                $transaction['inserted'],
                $batchSize,
                $startedAt,
                $sampleLimit,
                $transaction['batches'],
                $transaction['protected_records']
            );
        } catch (ControlledAsbisStagingApplyException $exception) {
            return $this->result(
                $audit,
                $mode,
                false,
                false,
                [$exception->reason],
                $candidatePayloads,
                $currentCount,
                $currentCount,
                0,
                0,
                $batchSize,
                $startedAt,
                $sampleLimit
            );
        } catch (Throwable) {
            return $this->result(
                $audit,
                $mode,
                false,
                false,
                ['transaction_failed'],
                $candidatePayloads,
                $currentCount,
                $currentCount,
                0,
                0,
                $batchSize,
                $startedAt,
                $sampleLimit
            );
        } finally {
            $this->executionLock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $sourceFingerprints
     * @param  array<int, array<string, mixed>>  $candidatePayloads
     * @param  array<int, string>  $conflicts
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function preflightReasons(array $audit, Supplier $supplier, array $sourceFingerprints, array $candidatePayloads, int $currentCount, array $conflicts, array $options, bool $apply): array
    {
        $reasons = [];
        $join = $audit['join'] ?? [];
        $identifierAudit = $audit['identifier_audit'] ?? [];
        $readiness = $audit['readiness'] ?? [];

        if ($this->normalizeKey($join['product_key'] ?? null) !== 'productcode' || $this->normalizeKey($join['price_key'] ?? null) !== 'wic') {
            $reasons[] = 'effective_join_key_mismatch';
        }

        if (! (bool) data_get($audit, 'parser.full_file_completed', false)) {
            $reasons[] = 'incomplete_full_file_parse';
        }

        if (! (bool) data_get($audit, 'reconciliation.reconciliation_valid', false)) {
            $reasons[] = 'reconciliation_failed';
        }

        if ((int) ($readiness['ready_to_update'] ?? 0) !== 0) {
            $reasons[] = 'ready_to_update_not_zero';
        }

        if ((int) ($identifierAudit['duplicate_product_code_keys'] ?? 0) !== 0) {
            $reasons[] = 'duplicate_product_code_blocker';
        }

        if ((int) ($identifierAudit['duplicate_wic_keys'] ?? 0) !== 0) {
            $reasons[] = 'duplicate_wic_blocker';
        }

        if ($candidatePayloads === []) {
            $reasons[] = 'no_ready_to_create_candidates';
        }

        if ($conflicts !== []) {
            $reasons[] = 'existing_asbis_staging_conflict';
        }

        if ($currentCount > 0) {
            $reasons[] = 'existing_asbis_staging_conflict';
        }

        if (! (bool) config('catalog_sync.create_enabled', true)
            || (bool) config('catalog_sync.update_enabled', false)
            || (bool) config('catalog_sync.sync_all_enabled', false)
            || (bool) config('catalog_sync.auto_enabled', false)) {
            $reasons[] = 'catalog_sync_flags_not_safe';
        }

        if ($supplier->schedule_enabled) {
            $reasons[] = 'asbis_schedule_enabled';
        }

        if ($apply && ! (bool) config('services.asbis_dual_feed_staging_apply.enabled', false)) {
            $reasons[] = 'apply_feature_disabled';
        }

        if (! $apply) {
            return array_values(array_unique($reasons));
        }

        if (strtolower((string) ($options['confirm_supplier'] ?? '')) !== self::SUPPLIER_KEY
            || (string) ($options['confirm_apply'] ?? '') !== 'ASBIS-DUAL-FEED-STAGING'
            || (string) ($options['confirm_create_only'] ?? '') !== 'CREATE_ONLY'
            || (string) ($options['confirm_no_catalog_sync'] ?? '') !== 'NO-CATALOG-SYNC') {
            $reasons[] = 'invalid_confirmation';
        }

        $expectedProductHash = trim((string) ($options['expected_product_list_sha256'] ?? ''));
        $expectedPriceHash = trim((string) ($options['expected_price_avail_sha256'] ?? ''));

        if ($expectedProductHash === '' || $expectedPriceHash === ''
            || ! hash_equals($expectedProductHash, (string) ($sourceFingerprints['product_list_sha256'] ?? ''))
            || ! hash_equals($expectedPriceHash, (string) ($sourceFingerprints['price_avail_sha256'] ?? ''))) {
            $reasons[] = 'source_fingerprint_mismatch';
        }

        $expectedReadyCount = $options['expected_ready_count'] ?? null;

        if ($expectedReadyCount === null || (int) $expectedReadyCount !== count($candidatePayloads)) {
            $reasons[] = 'candidate_count_mismatch';
        }

        $expectedCandidateHash = trim((string) ($options['expected_candidate_sha256'] ?? ''));

        if ($expectedCandidateHash === '' || ! hash_equals($expectedCandidateHash, $this->fingerprint->fingerprint($candidatePayloads))) {
            $reasons[] = 'candidate_set_fingerprint_mismatch';
        }

        $expectedStagedCount = $options['expected_asbis_staged_count'] ?? null;

        if ($expectedStagedCount === null || (int) $expectedStagedCount !== $currentCount) {
            $reasons[] = 'expected_asbis_staged_count_mismatch';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, string>
     */
    private function sourceFingerprints(array $audit): array
    {
        $paths = $audit['source_paths'] ?? [];

        return [
            'product_list_sha256' => is_file($paths['product_list'] ?? null) ? (string) hash_file('sha256', $paths['product_list']) : '',
            'price_avail_sha256' => is_file($paths['price_avail'] ?? null) ? (string) hash_file('sha256', $paths['price_avail']) : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $initial
     * @param  array<string, string>  $final
     */
    private function sameSourceFingerprints(array $initial, array $final): bool
    {
        return hash_equals((string) ($initial['product_list_sha256'] ?? ''), $final['product_list_sha256'])
            && hash_equals((string) ($initial['price_avail_sha256'] ?? ''), $final['price_avail_sha256']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidatePayloads
     * @return array<int, string>
     */
    private function existingSkuConflicts(Supplier $supplier, array $candidatePayloads): array
    {
        $candidateSkus = collect($candidatePayloads)
            ->pluck('supplier_sku')
            ->filter()
            ->map(fn (mixed $sku): string => $this->normalizeSku($sku))
            ->flip();

        if ($candidateSkus->isEmpty()) {
            return [];
        }

        return SupplierProduct::query()
            ->where('supplier_id', $supplier->getKey())
            ->pluck('supplier_sku')
            ->filter()
            ->map(fn (mixed $sku): string => $this->normalizeSku($sku))
            ->filter(fn (string $sku): bool => $candidateSkus->has($sku))
            ->unique()
            ->values()
            ->all();
    }

    private function supplierProductCount(Supplier $supplier): int
    {
        return SupplierProduct::query()->where('supplier_id', $supplier->getKey())->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidatePayloads
     */
    private function verifyInsertedSkus(Supplier $supplier, array $candidatePayloads): void
    {
        $expected = collect($candidatePayloads)
            ->pluck('supplier_sku')
            ->map(fn (mixed $sku): string => $this->normalizeSku($sku))
            ->unique()
            ->count();
        $actual = SupplierProduct::query()
            ->where('supplier_id', $supplier->getKey())
            ->pluck('supplier_sku')
            ->filter()
            ->map(fn (mixed $sku): string => $this->normalizeSku($sku))
            ->unique()
            ->count();

        if ($expected !== $actual) {
            throw new ControlledAsbisStagingApplyException(
                'post_insert_verification_failed',
                'The ASBIS staging SKU set did not match the candidate set after insert.'
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function protectedCounts(): array
    {
        $tables = [
            'products',
            'suppliers',
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

        return collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => Schema::hasTable($table) ? DB::table($table)->count() : 0,
        ])->all();
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function zeroProtectedRecords(array $counts): array
    {
        return collect($counts)->mapWithKeys(fn (int $count, string $table): array => [$table => 0])->all();
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<int, string>  $reasons
     * @param  array<int, array<string, mixed>>  $candidatePayloads
     * @return array<string, mixed>
     */
    private function result(array $audit, string $mode, bool $success, bool $transactionCommitted, array $reasons, array $candidatePayloads, int $stagedBefore, int $stagedAfter, int $attempted, int $inserted, int $batchSize, float $startedAt, int $sampleLimit = 20, int $batches = 0, array $protectedRecords = []): array
    {
        $safeAudit = $audit;
        unset($safeAudit['candidate_payloads'], $safeAudit['source_paths']);
        $recordsChanged = $this->emptyRecordsChanged();
        $recordsChanged['supplier_products'] = $inserted;

        return [
            'success' => $success,
            'mode' => $mode,
            'write_scope' => self::WRITE_SCOPE,
            'create_only' => true,
            'feature_enabled' => (bool) config('services.asbis_dual_feed_staging_apply.enabled', false),
            'feature_flags' => [
                'asbis_dual_feed_staging_apply_enabled' => (bool) config('services.asbis_dual_feed_staging_apply.enabled', false),
                'catalog_sync_create_enabled' => (bool) config('catalog_sync.create_enabled', true),
                'catalog_sync_update_enabled' => (bool) config('catalog_sync.update_enabled', false),
                'catalog_sync_sync_all_enabled' => (bool) config('catalog_sync.sync_all_enabled', false),
                'catalog_sync_auto_enabled' => (bool) config('catalog_sync.auto_enabled', false),
            ],
            'can_apply' => $success && $transactionCommitted,
            'transaction_committed' => $transactionCommitted,
            'refusal_reasons' => array_values(array_unique($reasons)),
            'audit' => $safeAudit,
            'summary' => $audit['summary'] ?? [],
            'readiness' => $audit['readiness'] ?? [],
            'issue_counts' => $audit['issue_counts'] ?? [],
            'supplier' => $audit['supplier'] ?? null,
            'parser' => $audit['parser'] ?? [],
            'join' => $audit['join'] ?? [],
            'reconciliation' => $audit['reconciliation'] ?? [],
            'source_fingerprints' => $audit['source_fingerprints'] ?? [],
            'candidate_payload_schema_version' => $audit['candidate_payload_schema_version'] ?? AsbisCandidateFingerprintService::SCHEMA_VERSION,
            'ready_to_create_candidate_set_sha256' => $audit['ready_to_create_candidate_set_sha256'] ?? $this->fingerprint->fingerprint([]),
            'ready_to_create_candidate_count' => (int) ($audit['ready_to_create_candidate_count'] ?? count($candidatePayloads)),
            'calculated_ready_count' => count($candidatePayloads),
            'expected_ready_count' => $this->expectedReadyCount,
            'expected_asbis_staged_count' => $this->expectedStagedCount,
            'current_asbis_staged_count' => $stagedBefore,
            'staged_before' => $stagedBefore,
            'staged_after' => $stagedAfter,
            'planned_insert_count' => count($candidatePayloads),
            'attempted_insert_count' => $attempted,
            'inserted_count' => $inserted,
            'batches' => $batches,
            'batch_size' => $batchSize,
            'skipped_count' => 0,
            'updated_count' => 0,
            'excluded_count' => (int) data_get($audit, 'readiness.apply_excluded_count', 0),
            'existing_conflict_count' => $this->existingConflictCount,
            'candidate_samples' => $this->candidateSamples($candidatePayloads, $sampleLimit),
            'protected_records' => $protectedRecords !== [] ? $protectedRecords : $this->zeroProtectedRecords($this->protectedCounts()),
            'records_changed' => $recordsChanged,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 4),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array<string, mixed>>
     */
    private function candidateSamples(array $payloads, int $limit): array
    {
        return collect($payloads)->take($limit)->map(fn (array $payload): array => [
            'supplier_sku' => $payload['supplier_sku'] ?? null,
            'ean' => $payload['ean'] ?? null,
            'name' => $payload['name'] ?? null,
            'price' => $payload['price'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'quantity' => $payload['quantity'] ?? null,
            'availability' => $payload['external_availability_status'] ?? null,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<int, string>
     */
    private function auditIssues(array $audit): array
    {
        return collect($audit['issues'] ?? [])
            ->pluck('reason')
            ->filter()
            ->map(fn (mixed $reason): string => (string) $reason)
            ->unique()
            ->values()
            ->all() ?: ['audit_failed'];
    }

    /**
     * @return array<string, int>
     */
    private function emptyRecordsChanged(): array
    {
        return [
            'products' => 0,
            'supplier_products' => 0,
            'suppliers' => 0,
            'categories' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
            'catalog_sync' => 0,
        ];
    }

    private function batchSize(mixed $value): int
    {
        return max(1, min((int) $value, self::MAX_BATCH_SIZE));
    }

    private function normalizeSku(mixed $value): string
    {
        return Str::upper(trim((string) $value));
    }

    private function normalizeKey(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?? '';
    }

    private function supplierKey(Supplier $supplier): string
    {
        return Str::slug((string) ($supplier->slug ?: $supplier->company_name));
    }
}
