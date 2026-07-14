<?php

namespace App\Services\Suppliers\Onboarding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Read-only import activity guard shared by local supplier source audits.
 */
final class SupplierImportActivityInspector
{
    private const ACTIVE_IMPORT_STATUSES = ['pending', 'queued', 'running', 'processing', 'started'];

    /** @return array{state: string, active_count: int, unknown_state_count: int, checked_sources: array<int, string>} */
    public function inspect(int $supplierId): array
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

                if ($normalized === '' || ! in_array($normalized, [...self::ACTIVE_IMPORT_STATUSES, 'completed', 'completed_with_warnings', 'failed', 'skipped', 'cancelled'], true)) {
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
}
