<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierScheduleSafetyCleanupService;
use Illuminate\Console\Command;

class CleanupUnsafeSupplierSchedules extends Command
{
    protected $signature = 'suppliers:cleanup-unsafe-schedules
        {--supplier= : Limit to supplier id, slug, or exact company name}
        {--limit=50 : Maximum rows to display}
        {--format=table : Output format: table or json}
        {--apply : Disable schedule_enabled for unsafe suppliers}
        {--dry-run : Preview changes without writing anything}
        {--only-unsafe : Show only suppliers whose schedules would be disabled}
        {--disable-schedules-only : Explicitly acknowledge apply mode only disables schedules}';

    protected $description = 'Dry-run-first cleanup for unsafe supplier import schedules.';

    public function handle(SupplierScheduleSafetyCleanupService $cleanup): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        if ((bool) $this->option('apply') && (bool) $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $payload = $cleanup->cleanup(
            supplier: filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            limit: (int) ($this->option('limit') ?: 50),
            apply: $apply,
            onlyUnsafe: (bool) $this->option('only-unsafe'),
            disableSchedulesOnly: (bool) $this->option('disable-schedules-only'),
        );

        return $format === 'json'
            ? $this->renderJson($payload)
            : $this->renderTable($payload, $apply);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderJson(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderTable(array $payload, bool $apply): int
    {
        $this->info($apply ? 'Supplier schedule safety cleanup applied.' : 'Dry-run only. No records were changed.');
        $this->line('No imports, feed fetches, queue jobs, Catalog Sync, or catalog writes were run.');

        $rows = collect($payload['rows'] ?? []);

        if ($rows->isEmpty()) {
            $this->warn('No suppliers matched the selected cleanup filters.');
        }

        $this->table([
            'ID',
            'Key',
            'Supplier',
            'Active',
            'Import',
            'Schedule before',
            'Schedule after',
            'Feed',
            'Driver',
            'Staged',
            'Readiness',
            'Issues',
            'Action',
        ], $rows->map(fn (array $row): array => [
            $row['supplier_id'],
            $row['supplier_key'] ?? '-',
            $row['supplier_name'],
            $row['active_status'] ?? '-',
            $this->boolLabel($row['import_enabled']),
            $this->boolLabel($row['schedule_enabled_before']),
            $this->boolLabel($row['schedule_enabled_after']),
            $this->boolLabel($row['feed_configured']),
            $this->boolLabel($row['driver_configured']),
            $row['staged_supplier_products_count'],
            $row['readiness'],
            $this->issuesLabel($row['issues'] ?? []),
            $row['action'],
        ])->all());

        $summary = $payload['summary'];

        $this->line('Suppliers checked: '.$summary['suppliers_checked']);
        $this->line('Unsafe scheduled suppliers: '.$summary['unsafe_scheduled_suppliers']);
        $this->line('Schedules to disable: '.$summary['schedules_to_disable']);
        $this->line('Schedules disabled: '.$summary['schedules_disabled']);
        $this->line('Suppliers skipped: '.$summary['suppliers_skipped']);

        if ($apply) {
            $this->line('Only supplier schedule flags were changed.');
        }

        $this->zeroChangeCounters($payload['records_changed']);
        $this->line('catalog_sync changed: '.$summary['catalog_sync_changed']);

        return self::SUCCESS;
    }

    private function boolLabel(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * @param  array<int, string>  $issues
     */
    private function issuesLabel(array $issues): string
    {
        return $issues === [] ? '-' : implode(', ', $issues);
    }

    /**
     * @param  array<string, int>  $recordsChanged
     */
    private function zeroChangeCounters(array $recordsChanged): void
    {
        foreach ($recordsChanged as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
