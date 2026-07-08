<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierImportCapabilityAuditService;
use Illuminate\Console\Command;

class AuditSupplierImportCapabilities extends Command
{
    protected $signature = 'suppliers:audit-import-capabilities
        {--supplier= : Limit to supplier id, slug, or exact company name}
        {--limit=50 : Maximum rows to display per section}
        {--format=table : Output format: table or json}
        {--include-disabled : Include disabled/inactive suppliers}
        {--only-with-issues : Show only suppliers with capability issues}
        {--show-drivers : Show static import driver/capability discovery}
        {--show-schedules : Show schedule readiness section}
        {--show-config : Show redacted feed/config section}
        {--show-checklist : Show readiness checklist}';

    protected $description = 'Read-only supplier import capability audit.';

    public function handle(SupplierImportCapabilityAuditService $audit): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        $payload = $audit->audit(
            supplier: filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            limit: (int) ($this->option('limit') ?: 50),
            includeDisabled: (bool) $this->option('include-disabled'),
            onlyWithIssues: (bool) $this->option('only-with-issues'),
            showDrivers: (bool) $this->option('show-drivers'),
            showSchedules: (bool) $this->option('show-schedules'),
            showConfig: (bool) $this->option('show-config'),
            showChecklist: (bool) $this->option('show-checklist'),
        );

        return $format === 'json'
            ? $this->renderJson($payload)
            : $this->renderTable($payload);
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
    private function renderTable(array $payload): int
    {
        $this->info('Supplier import capability audit');
        $this->line('Read-only. No imports, feed fetches, queue jobs, Catalog Sync, or catalog writes were run.');

        $supplierRows = collect($payload['suppliers'] ?? []);

        if ($supplierRows->isEmpty()) {
            $this->warn('No suppliers matched the selected capability filters.');
        }

        $this->table([
            'ID',
            'Supplier',
            'Status',
            'Feed type',
            'Driver',
            'Feed configured',
            'Auth configured',
            'Staged',
            'Last import',
            'Readiness',
            'Issues',
        ], $supplierRows->map(fn (array $row): array => [
            $row['supplier_id'],
            $row['supplier_name'],
            $this->statusLabel($row),
            $row['feed_type'],
            $this->shortClass($row['driver']),
            $row['feed_configured'] ? 'yes' : 'no',
            $row['auth_configured'] ? 'yes' : 'no',
            $row['staged_supplier_products_count'],
            $row['last_import_at'] ?? '-',
            $row['readiness_status'],
            $this->issuesLabel($row['issues'] ?? []),
        ])->all());

        $summary = $payload['summary'];
        $this->line('Suppliers checked: '.$summary['suppliers_checked']);
        $this->line('Suppliers returned: '.$summary['suppliers_returned']);
        $this->line('Active suppliers: '.$summary['active_suppliers']);
        $this->line('Disabled suppliers: '.$summary['disabled_suppliers']);
        $this->line('Suppliers with feed: '.$summary['suppliers_with_feed']);
        $this->line('Missing feed URL: '.$summary['suppliers_missing_feed_url']);
        $this->line('Missing import driver: '.$summary['suppliers_missing_import_driver']);
        $this->line('Schedule enabled: '.$summary['suppliers_with_schedule_enabled']);
        $this->line('Staged supplier_products: '.$summary['staged_supplier_products']);

        if ((bool) $this->option('show-drivers')) {
            $this->renderDrivers($payload['drivers'] ?? []);
        }

        if ((bool) $this->option('show-schedules')) {
            $this->renderSchedules($payload['schedules'] ?? []);
        }

        if ((bool) $this->option('show-config')) {
            $this->renderConfig($payload['config'] ?? []);
        }

        if ((bool) $this->option('show-checklist')) {
            $this->renderChecklist($payload['checklist'] ?? []);
        }

        $this->zeroChangeCounters($payload['records_changed']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function statusLabel(array $row): string
    {
        $parts = [
            $row['supplier_status'] ?? 'unknown',
        ];

        if ($row['import_enabled'] !== null) {
            $parts[] = $row['import_enabled'] ? 'import on' : 'import off';
        }

        if ($row['schedule_enabled'] !== null) {
            $parts[] = $row['schedule_enabled'] ? 'schedule on' : 'schedule off';
        }

        return implode(' / ', $parts);
    }

    /**
     * @param  array<int, string>  $issues
     */
    private function issuesLabel(array $issues): string
    {
        return $issues === [] ? '-' : implode(', ', $issues);
    }

    private function shortClass(mixed $class): string
    {
        if (! is_string($class) || $class === '') {
            return '-';
        }

        return class_basename($class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $drivers
     */
    private function renderDrivers(array $drivers): void
    {
        $this->line('');
        $this->info('Import driver capabilities');

        if ($drivers === []) {
            $this->line('- none');

            return;
        }

        $this->table([
            'Format/section',
            'Supported',
            'Importer/job',
            'Parser',
            'Staging write',
            'Catalog write',
        ], collect($drivers)->map(fn (array $row): array => [
            $row['format'] ?? $row['section'] ?? '-',
            array_key_exists('supported', $row) ? ($row['supported'] ? 'yes' : 'no') : '-',
            $this->driverDescription($row),
            $row['parser'] ?? '-',
            array_key_exists('writes_to_supplier_products_staging', $row)
                ? $this->nullableBoolLabel($row['writes_to_supplier_products_staging'])
                : '-',
            array_key_exists('catalog_product_write_detected', $row)
                ? $this->nullableBoolLabel($row['catalog_product_write_detected'])
                : '-',
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function driverDescription(array $row): string
    {
        if (isset($row['importer_class'])) {
            return $this->shortClass($row['importer_class']);
        }

        if (isset($row['commands']) && is_array($row['commands'])) {
            return implode(', ', $row['commands']);
        }

        if (isset($row['jobs']) && is_array($row['jobs'])) {
            return collect($row['jobs'])->map(fn (mixed $job): string => $this->shortClass($job))->implode(', ');
        }

        return (string) ($row['message'] ?? '-');
    }

    private function nullableBoolLabel(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        return $value ? 'yes' : 'no';
    }

    /**
     * @param  array<int, array<string, mixed>>  $schedules
     */
    private function renderSchedules(array $schedules): void
    {
        $this->line('');
        $this->info('Schedule readiness');

        if ($schedules === []) {
            $this->line('- none');

            return;
        }

        $this->table([
            'Supplier',
            'Import',
            'Schedule',
            'Type',
            'Next import',
            'Due now',
            'Job configured',
            'Risk',
        ], collect($schedules)->map(fn (array $row): array => [
            $row['supplier_name'],
            $this->nullableBoolLabel($row['import_enabled']),
            $this->nullableBoolLabel($row['schedule_enabled']),
            $row['schedule_type'] ?? '-',
            $row['next_import_at'] ?? '-',
            $this->nullableBoolLabel($row['due_now']),
            $row['queue_job_configured'] ? 'yes' : 'no',
            $row['risk_status'],
        ])->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $config
     */
    private function renderConfig(array $config): void
    {
        $this->line('');
        $this->info('Redacted feed configuration');

        if ($config === []) {
            $this->line('- none');

            return;
        }

        $this->table([
            'Supplier',
            'Feed type',
            'Host',
            'Redacted URL',
            'Auth markers',
            'Mapping',
            'XML template',
        ], collect($config)->map(fn (array $row): array => [
            $row['supplier_name'],
            $row['feed_type'],
            $row['feed_url_host'] ?? '-',
            $row['feed_url_redacted'] ?? '-',
            $this->authMarkers($row['auth'] ?? []),
            $this->nullableBoolLabel($row['mapping_configured']),
            $this->nullableBoolLabel($row['xml_mapping_template_configured']),
        ])->all());
    }

    /**
     * @param  array<string, bool>  $auth
     */
    private function authMarkers(array $auth): string
    {
        $markers = collect($auth)
            ->filter(fn (bool $present): bool => $present)
            ->keys()
            ->map(fn (string $key): string => str_replace('has_', '', $key))
            ->values()
            ->all();

        return $markers === [] ? '-' : implode(', ', $markers);
    }

    /**
     * @param  array<int, array<string, mixed>>  $checklist
     */
    private function renderChecklist(array $checklist): void
    {
        $this->line('');
        $this->info('Readiness checklist');

        if ($checklist === []) {
            $this->line('- none');

            return;
        }

        $this->table([
            'Check',
            'Status',
        ], collect($checklist)->map(fn (array $row): array => [
            $row['check'],
            $row['status'],
        ])->all());
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
