<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\ControlledSupplierScheduleFreezeReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ControlledSupplierScheduleFreezeService
{
    /** @var array<int, string> */
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

    /** @var array<int, string> */
    private const ACTIVE_IMPORT_STATUSES = [
        'pending',
        'queued',
        'running',
        'processing',
        'started',
    ];

    /** @var array<string, bool> */
    private array $globalSafetyFlags;

    public function __construct()
    {
        $this->globalSafetyFlags = [
            'catalog_sync_create_enabled' => (bool) config('catalog_sync.create_enabled', true),
            'catalog_sync_update_enabled' => (bool) config('catalog_sync.update_enabled', false),
            'catalog_sync_sync_all_enabled' => (bool) config('catalog_sync.sync_all_enabled', false),
            'catalog_sync_auto_enabled' => (bool) config('catalog_sync.auto_enabled', false),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function freeze(array $options): ControlledSupplierScheduleFreezeReport
    {
        $startedAt = microtime(true);
        $apply = (bool) ($options['apply'] ?? false);
        $protectedBefore = $this->protectedCounts();
        $expected = $this->expectedState($options);
        $supplierInput = trim((string) ($options['supplier'] ?? ''));
        $resolution = $this->resolveSupplier($supplierInput);

        if ($resolution['supplier'] === null) {
            return $this->report(
                options: $options,
                apply: $apply,
                startedAt: $startedAt,
                protectedBefore: $protectedBefore,
                supplier: null,
                observedBefore: null,
                observedAfter: null,
                refusalReasons: [$resolution['reason']],
                warnings: [],
            );
        }

        $supplier = $resolution['supplier'];
        $observedBefore = $this->observedState($supplier);
        $activeImportCheck = $this->activeImportCheck((int) $supplier->id);
        $refusalReasons = $this->validationReasons($supplier, $observedBefore, $activeImportCheck, $expected, $options, $apply);

        if ($refusalReasons !== [] || ! $apply) {
            return $this->report(
                options: $options,
                apply: $apply,
                startedAt: $startedAt,
                protectedBefore: $protectedBefore,
                supplier: $supplier,
                observedBefore: $observedBefore,
                observedAfter: $observedBefore,
                refusalReasons: $refusalReasons,
                warnings: $apply ? [] : ['apply_requires_explicit_confirmation'],
                activeImportCheck: $activeImportCheck,
            );
        }

        $transactionAttempted = true;
        $attemptedSupplierChanges = 0;

        try {
            $result = DB::transaction(function () use ($supplier, $options, $expected, $protectedBefore, &$attemptedSupplierChanges): array {
                $freshSupplier = DB::table('suppliers')
                    ->where('id', $supplier->id)
                    ->lockForUpdate()
                    ->first();

                if ($freshSupplier === null) {
                    $this->abort('protected_state_changed');
                }

                if ((string) ($freshSupplier->slug ?? '') !== (string) ($supplier->slug ?? '')
                    || (string) ($freshSupplier->company_name ?? '') !== (string) ($supplier->company_name ?? '')) {
                    $this->abort('protected_state_changed');
                }

                $freshObserved = $this->observedState($freshSupplier);
                $freshActiveImportCheck = $this->activeImportCheck((int) $supplier->id);
                $freshReasons = $this->validationReasons(
                    $supplier,
                    $freshObserved,
                    $freshActiveImportCheck,
                    $expected,
                    $options,
                    true,
                );

                if ($freshReasons !== []) {
                    $this->abort($freshReasons[0]);
                }

                if ($this->protectedCounts() !== $protectedBefore) {
                    $this->abort('protected_state_changed');
                }

                $attemptedSupplierChanges = 1;
                $changed = DB::table('suppliers')
                    ->where('id', $supplier->id)
                    ->where('schedule_enabled', true)
                    ->update(['schedule_enabled' => false]);

                if ($changed !== 1) {
                    $this->abort('schedule_already_disabled');
                }

                $afterSupplier = DB::table('suppliers')
                    ->where('id', $supplier->id)
                    ->first();

                if ($afterSupplier === null) {
                    $this->abort('protected_state_changed');
                }

                $after = $this->observedState($afterSupplier);
                $afterCounts = $this->protectedCounts();

                if (($after['schedule_enabled'] ?? null) !== false
                    || ($after['import_enabled'] ?? null) !== $freshObserved['import_enabled']
                    || ($after['schedule_type'] ?? null) !== $freshObserved['schedule_type']
                    || ($after['staged_count'] ?? null) !== $freshObserved['staged_count']
                    || ($after['linked_count'] ?? null) !== $freshObserved['linked_count']
                    || ($after['unlinked_count'] ?? null) !== $freshObserved['unlinked_count']
                    || $afterCounts !== $protectedBefore
                    || ! $this->safeConfiguration()) {
                    $this->abort('postcondition_failed');
                }

                return [
                    'observed_after' => $after,
                    'protected_after' => $afterCounts,
                    'active_import_check' => $freshActiveImportCheck,
                ];
            });
        } catch (Throwable $exception) {
            $reason = $this->transactionReason($exception);

            return $this->report(
                options: $options,
                apply: true,
                startedAt: $startedAt,
                protectedBefore: $protectedBefore,
                supplier: $supplier,
                observedBefore: $observedBefore,
                observedAfter: $observedBefore,
                refusalReasons: [$reason],
                warnings: [],
                transactionAttempted: true,
                attemptedSupplierChanges: $attemptedSupplierChanges,
                activeImportCheck: $activeImportCheck,
            );
        }

        return $this->report(
            options: $options,
            apply: true,
            startedAt: $startedAt,
            protectedBefore: $protectedBefore,
            protectedAfter: $result['protected_after'],
            supplier: $supplier,
            observedBefore: $observedBefore,
            observedAfter: $result['observed_after'],
            refusalReasons: [],
            warnings: [],
            transactionAttempted: $transactionAttempted,
            transactionCommitted: true,
            attemptedSupplierChanges: $attemptedSupplierChanges,
            committedSupplierChanges: 1,
            scheduleStateChanged: 1,
            activeImportCheck: $result['active_import_check'],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, int>  $protectedBefore
     * @param  array<string, int>|null  $protectedAfter
     * @param  array<string, mixed>|null  $observedBefore
     * @param  array<string, mixed>|null  $observedAfter
     * @param  array<int, string>  $refusalReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>|null  $activeImportCheck
     */
    private function report(
        array $options,
        bool $apply,
        float $startedAt,
        array $protectedBefore,
        ?object $supplier,
        ?array $observedBefore,
        ?array $observedAfter,
        array $refusalReasons,
        array $warnings,
        ?array $protectedAfter = null,
        bool $transactionAttempted = false,
        bool $transactionCommitted = false,
        int $attemptedSupplierChanges = 0,
        int $committedSupplierChanges = 0,
        int $scheduleStateChanged = 0,
        ?array $activeImportCheck = null,
    ): ControlledSupplierScheduleFreezeReport {
        $refusalReasons = array_values(array_unique($refusalReasons));
        $protectedAfter ??= $protectedBefore;
        $recordsChanged = $this->zeroRecordsChanged();
        $recordsChanged['suppliers'] = $committedSupplierChanges;
        $dryRun = ! $apply;
        $softDryRunState = $dryRun && $refusalReasons === ['schedule_already_disabled'];
        $success = $apply
            ? $transactionCommitted && $refusalReasons === []
            : ($refusalReasons === [] || $softDryRunState);
        $canApply = $refusalReasons === [] && $observedBefore !== null && ($observedBefore['schedule_enabled'] ?? false) === true;
        $verdict = $this->verdict($apply, $success, $refusalReasons);
        $activeImportCheck ??= [
            'status' => 'unknown',
            'blocking_reason' => 'import_state_unknown',
            'inspected_tables' => [],
            'active_counts' => [],
        ];

        return new ControlledSupplierScheduleFreezeReport([
            'mode' => $apply ? 'apply' : 'dry_run',
            'success' => $success,
            'dry_run' => $dryRun,
            'apply_requested' => $apply,
            'can_apply' => $canApply,
            'read_scope' => [
                'supplier_fields' => ['id', 'slug', 'company_name', 'status', 'import_enabled', 'schedule_enabled', 'schedule_type', 'last_import_at'],
                'staging_fields' => ['supplier_id', 'product_id'],
                'import_state' => ['supplier_import_runs', 'import_jobs'],
                'protected_table_counts' => self::PROTECTED_TABLES,
                'catalog_sync_flags' => array_keys($this->globalSafetyFlags),
            ],
            'write_scope' => [
                'table' => 'suppliers',
                'column' => 'schedule_enabled',
                'from' => true,
                'to' => false,
            ],
            'supplier' => $supplier === null ? null : $this->supplierSummary($supplier),
            'confirmations' => [
                'supplier' => filled($options['confirm_supplier'] ?? null) ? (string) $options['confirm_supplier'] : null,
                'action' => filled($options['confirm_action'] ?? null) ? (string) $options['confirm_action'] : null,
                'write_scope' => filled($options['confirm_write_scope'] ?? null) ? (string) $options['confirm_write_scope'] : null,
                'scheduler_stopped' => (bool) ($options['confirm_scheduler_stopped'] ?? false),
                'reason_provided' => filled($options['reason'] ?? null),
            ],
            'expected_state' => $this->expectedState($options),
            'observed_state_before' => $observedBefore,
            'planned_state_after' => $observedBefore === null ? null : array_merge($observedBefore, ['schedule_enabled' => false]),
            'observed_state_after' => $observedAfter,
            'global_safety_flags' => $this->globalSafetyFlags,
            'active_import_check' => $activeImportCheck,
            'transaction_attempted' => $transactionAttempted,
            'transaction_committed' => $transactionCommitted,
            'attempted_supplier_changes' => $attemptedSupplierChanges,
            'committed_supplier_changes' => $committedSupplierChanges,
            'schedule_state_changed' => $scheduleStateChanged,
            'import_enabled_changed' => 0,
            'schedule_type_changed' => 0,
            'refusal_reasons' => $refusalReasons,
            'warnings' => $warnings,
            'issue_counts' => [
                'refusal_reasons' => count($refusalReasons),
                'warnings' => count($warnings),
            ],
            'issues' => array_map(fn (string $reason): array => ['code' => $reason], array_slice($refusalReasons, 0, max(1, (int) ($options['issue_sample_limit'] ?? 20)))),
            'protected_counts_before' => $protectedBefore,
            'protected_counts_after' => $protectedAfter,
            'records_changed' => $recordsChanged,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 6),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'verdict' => $verdict,
        ]);
    }

    /**
     * @param  array<string, mixed>  $observed
     * @param  array<string, mixed>  $activeImportCheck
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function validationReasons(object $supplier, array $observed, array $activeImportCheck, array $expected, array $options, bool $apply): array
    {
        $reasons = [];

        if ($apply) {
            $reasons = array_merge($reasons, $this->applyRequirements($supplier, $options));
        }

        if (! $this->safeConfiguration()) {
            $reasons[] = 'unsafe_catalog_sync_flags';
        }

        if (($observed['schedule_enabled'] ?? null) !== true) {
            $reasons[] = 'schedule_already_disabled';
        }

        if (($activeImportCheck['status'] ?? 'unknown') === 'active') {
            $reasons[] = 'import_currently_running';
        } elseif (($activeImportCheck['status'] ?? 'unknown') === 'unknown') {
            $reasons[] = 'import_state_unknown';
        }

        if ($observed['staged_count'] === null || $observed['linked_count'] === null || $observed['unlinked_count'] === null) {
            $reasons[] = 'staging_state_unknown';
        } elseif (($observed['linked_count'] + $observed['unlinked_count']) !== $observed['staged_count']) {
            $reasons[] = 'staging_count_reconciliation_mismatch';
        }

        $reasons = array_merge($reasons, $this->expectedMismatches($observed, $expected));

        return array_values(array_unique($reasons));
    }

    /** @param array<string, mixed> $options @return array<int, string> */
    private function applyRequirements(object $supplier, array $options): array
    {
        $reasons = [];

        if (trim((string) ($options['confirm_supplier'] ?? '')) === '') {
            $reasons[] = 'supplier_confirmation_mismatch';
        } elseif (Str::lower(trim((string) $options['confirm_supplier'])) !== Str::lower((string) $supplier->slug)) {
            $reasons[] = 'supplier_confirmation_mismatch';
        }

        if (($options['confirm_action'] ?? null) !== 'freeze-for-audit') {
            $reasons[] = 'action_confirmation_mismatch';
        }

        if (($options['confirm_write_scope'] ?? null) !== 'schedule-enabled-only') {
            $reasons[] = 'write_scope_confirmation_mismatch';
        }

        if (! (bool) ($options['confirm_scheduler_stopped'] ?? false)) {
            $reasons[] = 'scheduler_stop_not_confirmed';
        }

        if (! filled($options['expected_supplier_id'] ?? null)) {
            $reasons[] = 'expected_supplier_id_mismatch';
        }

        if ($this->parseBoolean($options['expected_schedule_enabled'] ?? null) !== true) {
            $reasons[] = 'expected_schedule_state_mismatch';
        }

        if (! filled($options['expected_schedule_type'] ?? null)) {
            $reasons[] = 'expected_schedule_type_mismatch';
        }

        if ($this->parseBoolean($options['expected_import_enabled'] ?? null) === null) {
            $reasons[] = 'expected_import_state_mismatch';
        }

        foreach (['expected_staged_count' => 'expected_staged_count_mismatch', 'expected_linked_count' => 'expected_linked_count_mismatch', 'expected_unlinked_count' => 'expected_unlinked_count_mismatch'] as $option => $reason) {
            if (! filled($options[$option] ?? null) || ! is_numeric($options[$option])) {
                $reasons[] = $reason;
            }
        }

        if (! filled($options['reason'] ?? null)) {
            $reasons[] = 'reason_required';
        }

        return $reasons;
    }

    /** @param array<string, mixed> $observed @param array<string, mixed> $expected @return array<int, string> */
    private function expectedMismatches(array $observed, array $expected): array
    {
        $mismatches = [];

        if (array_key_exists('supplier_id', $expected) && (int) $expected['supplier_id'] !== (int) $observed['supplier_id']) {
            $mismatches[] = 'expected_supplier_id_mismatch';
        }

        if (array_key_exists('schedule_enabled', $expected) && $expected['schedule_enabled'] !== $observed['schedule_enabled']) {
            $mismatches[] = 'expected_schedule_state_mismatch';
        }

        if (array_key_exists('schedule_type', $expected) && (string) $expected['schedule_type'] !== (string) $observed['schedule_type']) {
            $mismatches[] = 'expected_schedule_type_mismatch';
        }

        if (array_key_exists('import_enabled', $expected) && $expected['import_enabled'] !== $observed['import_enabled']) {
            $mismatches[] = 'expected_import_state_mismatch';
        }

        foreach (['staged_count' => 'expected_staged_count_mismatch', 'linked_count' => 'expected_linked_count_mismatch', 'unlinked_count' => 'expected_unlinked_count_mismatch'] as $key => $reason) {
            if (array_key_exists($key, $expected) && (int) $expected[$key] !== (int) $observed[$key]) {
                $mismatches[] = $reason;
            }
        }

        if (array_key_exists('last_import_at', $expected) && $expected['last_import_at'] !== $observed['last_import_at']) {
            $mismatches[] = 'expected_last_import_at_mismatch';
        }

        return $mismatches;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function expectedState(array $options): array
    {
        $expected = [];

        if (filled($options['expected_supplier_id'] ?? null) && is_numeric($options['expected_supplier_id'])) {
            $expected['supplier_id'] = (int) $options['expected_supplier_id'];
        }

        foreach ([
            'expected_schedule_enabled' => 'schedule_enabled',
            'expected_import_enabled' => 'import_enabled',
        ] as $option => $key) {
            $value = $this->parseBoolean($options[$option] ?? null);

            if ($value !== null) {
                $expected[$key] = $value;
            }
        }

        if (filled($options['expected_schedule_type'] ?? null)) {
            $expected['schedule_type'] = (string) $options['expected_schedule_type'];
        }

        foreach ([
            'expected_staged_count' => 'staged_count',
            'expected_linked_count' => 'linked_count',
            'expected_unlinked_count' => 'unlinked_count',
        ] as $option => $key) {
            if (filled($options[$option] ?? null) && is_numeric($options[$option])) {
                $expected[$key] = (int) $options[$option];
            }
        }

        if (filled($options['expected_last_import_at'] ?? null)) {
            $expected['last_import_at'] = $this->normalizeDate((string) $options['expected_last_import_at']);
        }

        return $expected;
    }

    /** @return array{supplier: object|null, reason: string} */
    private function resolveSupplier(string $value): array
    {
        if ($value === '' || ! Schema::hasTable('suppliers')) {
            return ['supplier' => null, 'reason' => 'invalid_supplier'];
        }

        $query = DB::table('suppliers')->select([
            'id', 'company_name', 'slug', 'status', 'import_enabled', 'schedule_enabled',
            'schedule_type', 'last_import_at', 'next_import_at',
        ]);

        if (is_numeric($value)) {
            $query->where('id', (int) $value);
        } else {
            $normalized = Str::lower($value);
            $query->where(function (Builder $supplier) use ($normalized): void {
                $supplier
                    ->whereRaw('LOWER(slug) = ?', [$normalized])
                    ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
            });
        }

        $matches = $query->limit(2)->get();

        if ($matches->count() === 0) {
            return ['supplier' => null, 'reason' => 'invalid_supplier'];
        }

        if ($matches->count() > 1) {
            return ['supplier' => null, 'reason' => 'ambiguous_supplier'];
        }

        return ['supplier' => $matches->first(), 'reason' => ''];
    }

    /** @param object $supplier @return array<string, mixed> */
    private function observedState(object $supplier): array
    {
        $staging = $this->stagingCounts((int) $supplier->id);

        return [
            'supplier_id' => (int) $supplier->id,
            'supplier_key' => (string) $supplier->slug,
            'supplier_name' => (string) $supplier->company_name,
            'status' => (string) $supplier->status,
            'import_enabled' => (bool) $supplier->import_enabled,
            'schedule_enabled' => (bool) $supplier->schedule_enabled,
            'schedule_type' => (string) $supplier->schedule_type,
            'last_import_at' => $this->normalizeDate($supplier->last_import_at ?? null),
            'staged_count' => $staging['staged_count'],
            'linked_count' => $staging['linked_count'],
            'unlinked_count' => $staging['unlinked_count'],
        ];
    }

    /** @param object $supplier @return array<string, mixed> */
    private function supplierSummary(object $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'key' => (string) $supplier->slug,
            'name' => (string) $supplier->company_name,
            'status' => (string) $supplier->status,
        ];
    }

    /** @return array{staged_count: int|null, linked_count: int|null, unlinked_count: int|null} */
    private function stagingCounts(int $supplierId): array
    {
        if (! Schema::hasTable('supplier_products')
            || ! Schema::hasColumn('supplier_products', 'supplier_id')
            || ! Schema::hasColumn('supplier_products', 'product_id')) {
            return ['staged_count' => null, 'linked_count' => null, 'unlinked_count' => null];
        }

        $query = DB::table('supplier_products')->where('supplier_id', $supplierId);

        return [
            'staged_count' => (clone $query)->count(),
            'linked_count' => (clone $query)->whereNotNull('product_id')->count(),
            'unlinked_count' => (clone $query)->whereNull('product_id')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function activeImportCheck(int $supplierId): array
    {
        $tables = ['supplier_import_runs', 'import_jobs'];
        $inspected = [];
        $counts = [];
        $unknown = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $unknown[] = $table;

                continue;
            }

            if (! Schema::hasColumn($table, 'supplier_id') || ! Schema::hasColumn($table, 'status')) {
                $unknown[] = $table;

                continue;
            }

            try {
                $query = DB::table($table)
                    ->where('supplier_id', $supplierId)
                    ->where(function (Builder $import) use ($table): void {
                        $import->whereIn('status', self::ACTIVE_IMPORT_STATUSES);

                        if (Schema::hasColumn($table, 'started_at') && Schema::hasColumn($table, 'finished_at')) {
                            $import->orWhere(function (Builder $unfinished): void {
                                $unfinished->whereNotNull('started_at')->whereNull('finished_at');
                            });
                        }
                    });

                $counts[$table] = (int) $query->count();
                $inspected[] = $table;
            } catch (Throwable) {
                $unknown[] = $table;
            }
        }

        if ($unknown !== [] || $inspected === []) {
            return [
                'status' => 'unknown',
                'blocking_reason' => 'import_state_unknown',
                'inspected_tables' => $inspected,
                'active_counts' => $counts,
            ];
        }

        $active = array_sum($counts);

        return [
            'status' => $active > 0 ? 'active' : 'clear',
            'blocking_reason' => $active > 0 ? 'import_currently_running' : null,
            'inspected_tables' => $inspected,
            'active_counts' => $counts,
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

    /** @return array<string, int> */
    private function zeroRecordsChanged(): array
    {
        return array_fill_keys(array_merge(self::PROTECTED_TABLES, ['catalog_sync']), 0);
    }

    private function safeConfiguration(): bool
    {
        return $this->globalSafetyFlags === [
            'catalog_sync_create_enabled' => true,
            'catalog_sync_update_enabled' => false,
            'catalog_sync_sync_all_enabled' => false,
            'catalog_sync_auto_enabled' => false,
        ];
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toISOString();
        } catch (Throwable) {
            return null;
        }
    }

    private function transactionReason(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $prefix = 'controlled_schedule_freeze:';

        return str_starts_with($message, $prefix)
            ? substr($message, strlen($prefix))
            : 'transaction_failed';
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('controlled_schedule_freeze:'.$reason);
    }

    /** @param array<int, string> $reasons */
    private function verdict(bool $apply, bool $success, array $reasons): string
    {
        if ($reasons !== []) {
            if (in_array('schedule_already_disabled', $reasons, true)) {
                return 'schedule_already_disabled';
            }

            if (in_array('import_currently_running', $reasons, true)) {
                return 'active_import_detected';
            }

            if (in_array('unsafe_catalog_sync_flags', $reasons, true)) {
                return 'unsafe_configuration';
            }

            return 'state_mismatch';
        }

        if ($success) {
            return $apply ? 'schedule_frozen' : 'dry_run_ready';
        }

        return $apply ? 'transaction_failed' : 'dry_run_refused';
    }
}
