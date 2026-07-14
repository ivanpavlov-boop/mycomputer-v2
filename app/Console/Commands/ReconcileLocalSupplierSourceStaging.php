<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Onboarding\LocalSupplierSourceStagingReconciler;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileLocalSupplierSourceStaging extends Command
{
    protected $signature = 'suppliers:reconcile-local-source-staging
        {--supplier= : Required existing supplier key or ID}
        {--source= : Required local XML file path or synthetic test fixture}
        {--source-format=xml : Local source format; only xml is supported}
        {--record-path= : Optional record path; must match the selected semantics profile}
        {--semantics-profile= : Required versioned official field semantics profile}
        {--expected-sha256= : Required SHA-256 fingerprint of the local source}
        {--full-file : Parse the complete local file; streaming is always used}
        {--expected-supplier-id= : Required expected supplier ID baseline}
        {--expected-schedule-enabled= : Required expected schedule enabled baseline}
        {--expected-import-enabled= : Required expected import enabled baseline}
        {--expected-schedule-type= : Required expected schedule type baseline}
        {--expected-staged-count= : Required expected supplier_products count baseline}
        {--expected-linked-count= : Required expected linked supplier_products count baseline}
        {--expected-unlinked-count= : Required expected unlinked supplier_products count baseline}
        {--expected-last-import-at= : Required expected last import timestamp baseline}
        {--output=table : Output format: table or json}
        {--summary-only : Suppress detailed tables in table output}
        {--sample-limit=20 : Maximum bounded hashes per sample bucket}
        {--issue-sample-limit=20 : Maximum bounded issue samples}';

    protected $description = 'Read-only local source-to-staging reconciliation; no remote resources, imports, writes, jobs, schedules, images, or Catalog Sync.';

    public function handle(LocalSupplierSourceStagingReconciler $reconciler): int
    {
        $output = strtolower((string) ($this->option('output') ?: 'table'));
        if (! in_array($output, ['table', 'json'], true)) {
            $this->error('Unsupported output. Use table or json.');

            return self::FAILURE;
        }

        try {
            $report = $reconciler->reconcile([
                'supplier' => $this->option('supplier'),
                'source' => $this->option('source'),
                'source_format' => $this->option('source-format'),
                'record_path' => $this->option('record-path'),
                'semantics_profile' => $this->option('semantics-profile'),
                'expected_sha256' => $this->option('expected-sha256'),
                'full_file' => (bool) $this->option('full-file'),
                'expected_supplier_id' => $this->option('expected-supplier-id'),
                'expected_schedule_enabled' => $this->option('expected-schedule-enabled'),
                'expected_import_enabled' => $this->option('expected-import-enabled'),
                'expected_schedule_type' => $this->option('expected-schedule-type'),
                'expected_staged_count' => $this->option('expected-staged-count'),
                'expected_linked_count' => $this->option('expected-linked-count'),
                'expected_unlinked_count' => $this->option('expected-unlinked-count'),
                'expected_last_import_at' => $this->option('expected-last-import-at'),
                'sample_limit' => (int) ($this->option('sample-limit') ?: 20),
                'issue_sample_limit' => (int) ($this->option('issue-sample-limit') ?: 20),
            ]);
        } catch (Throwable) {
            $this->error('Local source-to-staging reconciliation failed safely.');

            return self::FAILURE;
        }

        if ($output === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($report->toArray(), (bool) $this->option('summary-only'));
        }

        return $report->success ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function renderTable(array $report, bool $summaryOnly): void
    {
        $this->info('Local supplier source-to-staging reconciliation');
        $this->line('Read-only. No remote resource, import, write, job, schedule change, image action, or Catalog Sync was run.');
        $this->line('Verdict: '.($report['verdict'] ?? '-'));
        $this->table(['Metric', 'Value'], [
            ['Supplier', data_get($report, 'supplier.key', '-')],
            ['Source file', data_get($report, 'source.file_name', '-')],
            ['Source SHA-256', data_get($report, 'source_fingerprint.sha256', '-')],
            ['Source records', data_get($report, 'source_aggregates.source_record_count', 0)],
            ['Staging records', data_get($report, 'staging_aggregates.row_count', 0)],
            ['Exact one-to-one SKU matches', data_get($report, 'exact_supplier_sku_reconciliation.exact_one_to_one_match_count', 0)],
            ['Source-only SKUs', data_get($report, 'exact_supplier_sku_reconciliation.source_only_sku_count', 0)],
            ['Staging-only SKUs', data_get($report, 'exact_supplier_sku_reconciliation.staging_only_sku_count', 0)],
            ['Blockers', count((array) ($report['blockers'] ?? []))],
            ['Warnings', count((array) ($report['warnings'] ?? []))],
        ]);

        if (! $summaryOnly) {
            $this->table(['Policy', 'Value'], [
                ['Official semantics profile', data_get($report, 'semantics_profile.key', '-')],
                ['Exact SKU matching authoritative', data_get($report, 'exact_supplier_sku_reconciliation.authoritative_match_rule', '-')],
                ['Normalized matching diagnostic only', data_get($report, 'normalized_match_diagnostics.normalization_is_diagnostic_only', false) ? 'yes' : 'no'],
                ['Automatic mapping/import allowed', data_get($report, 'automatic_mapping_or_import_allowed', false) ? 'yes' : 'no'],
            ]);
        }

        foreach ((array) ($report['records_changed'] ?? []) as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
