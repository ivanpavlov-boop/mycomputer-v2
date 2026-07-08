<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SupplierScheduleSafetyCleanupService
{
    private const UNSAFE_READINESS = [
        'missing_feed_url',
        'missing_import_driver',
        'no_staging_data',
    ];

    public function __construct(private readonly SupplierImportCapabilityAuditService $capabilities) {}

    /**
     * @return array<string, mixed>
     */
    public function cleanup(
        ?string $supplier = null,
        int $limit = 50,
        bool $apply = false,
        bool $onlyUnsafe = false,
        bool $disableSchedulesOnly = false,
    ): array {
        $limit = max(1, min($limit, 5000));

        $capabilityPayload = $this->capabilities->audit(
            supplier: $supplier,
            limit: 5000,
            includeDisabled: false,
            onlyWithIssues: false,
        );

        $rows = collect($capabilityPayload['suppliers'] ?? [])
            ->map(fn (array $row): array => $this->cleanupRow($row, $apply))
            ->values();

        $unsafeRows = $rows
            ->filter(fn (array $row): bool => (bool) $row['unsafe_scheduled_supplier'])
            ->values();

        $disabledSupplierIds = [];

        if ($apply && $unsafeRows->isNotEmpty()) {
            $disabledSupplierIds = DB::transaction(function () use ($unsafeRows): array {
                $changed = [];

                foreach ($unsafeRows as $row) {
                    $updated = Supplier::query()
                        ->whereKey($row['supplier_id'])
                        ->where('status', 'active')
                        ->where('import_enabled', true)
                        ->where('schedule_enabled', true)
                        ->update(['schedule_enabled' => false]);

                    if ($updated === 1) {
                        $changed[] = (int) $row['supplier_id'];
                    }
                }

                return $changed;
            });

            $rows = $rows
                ->map(function (array $row) use ($disabledSupplierIds): array {
                    if (in_array((int) $row['supplier_id'], $disabledSupplierIds, true)) {
                        $row['schedule_enabled_after'] = false;
                        $row['action'] = 'disabled_schedule';
                    }

                    return $row;
                })
                ->values();
        }

        $recordsChanged = $this->capabilities->recordsChanged();
        $recordsChanged['suppliers'] = $apply ? count($disabledSupplierIds) : 0;

        $displayRows = $onlyUnsafe
            ? $rows->filter(fn (array $row): bool => in_array($row['action'], ['would_disable_schedule', 'disabled_schedule'], true))->values()
            : $rows;

        return [
            'summary' => [
                'dry_run' => ! $apply,
                'apply' => $apply,
                'disable_schedules_only' => $disableSchedulesOnly || $apply,
                'suppliers_checked' => $rows->count(),
                'unsafe_scheduled_suppliers' => $unsafeRows->count(),
                'schedules_to_disable' => $unsafeRows->count(),
                'schedules_disabled' => count($disabledSupplierIds),
                'suppliers_skipped' => $rows->count() - count($disabledSupplierIds),
                'display_limit' => $limit,
                'catalog_sync_changed' => 0,
                'records_changed' => $recordsChanged,
            ],
            'rows' => $displayRows->take($limit)->values()->all(),
            'records_changed' => $recordsChanged,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function cleanupRow(array $row, bool $apply): array
    {
        $feedConfigured = (bool) ($row['feed_configured'] ?? false);
        $driverConfigured = ($row['driver_status'] ?? null) === 'configured';
        $stagedCount = (int) ($row['staged_supplier_products_count'] ?? 0);
        $unsafe = $this->isUnsafeScheduledSupplier($row);
        $scheduleBefore = (bool) ($row['schedule_enabled'] ?? false);

        return [
            'supplier_id' => (int) $row['supplier_id'],
            'supplier_key' => $row['supplier_key'],
            'supplier_name' => $row['supplier_name'],
            'active_status' => $row['supplier_status'],
            'import_enabled' => (bool) ($row['import_enabled'] ?? false),
            'schedule_enabled_before' => $scheduleBefore,
            'schedule_enabled_after' => $scheduleBefore,
            'feed_configured' => $feedConfigured,
            'driver_configured' => $driverConfigured,
            'staged_supplier_products_count' => $stagedCount,
            'readiness' => $row['readiness_status'],
            'issues' => $row['issues'] ?? [],
            'unsafe_scheduled_supplier' => $unsafe,
            'action' => $this->actionFor($row, $unsafe, $apply),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isUnsafeScheduledSupplier(array $row): bool
    {
        $feedConfigured = (bool) ($row['feed_configured'] ?? false);
        $driverConfigured = ($row['driver_status'] ?? null) === 'configured';
        $readiness = (string) ($row['readiness_status'] ?? '');

        return ($row['supplier_status'] ?? null) === 'active'
            && ($row['import_enabled'] ?? false) === true
            && ($row['schedule_enabled'] ?? false) === true
            && (! $feedConfigured || ! $driverConfigured)
            && (int) ($row['staged_supplier_products_count'] ?? 0) === 0
            && in_array($readiness, self::UNSAFE_READINESS, true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function actionFor(array $row, bool $unsafe, bool $apply): string
    {
        if (! ($row['schedule_enabled'] ?? false)) {
            return 'skipped_already_disabled';
        }

        if ($unsafe) {
            return $apply ? 'disabled_schedule' : 'would_disable_schedule';
        }

        if ((int) ($row['staged_supplier_products_count'] ?? 0) > 0 && (! ($row['feed_configured'] ?? false) || ($row['driver_status'] ?? null) !== 'configured')) {
            return 'skipped_has_staging';
        }

        if (($row['feed_configured'] ?? false) && ($row['driver_status'] ?? null) === 'configured') {
            return 'no_action_safe';
        }

        return 'skipped_manual_review_required';
    }
}
