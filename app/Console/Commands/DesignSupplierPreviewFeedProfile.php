<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesigner;
use Illuminate\Console\Command;
use Throwable;

final class DesignSupplierPreviewFeedProfile extends Command
{
    protected $signature = 'suppliers:design-preview-feed-profile
        {--supplier= : Required existing supplier key or ID}
        {--source= : Required local XML file path or synthetic test fixture}
        {--source-format=xml : Local source format; only xml is supported}
        {--record-path= : Optional record path; must match the selected semantics profile}
        {--semantics-profile= : Required versioned field semantics profile}
        {--decision-register= : Required versioned human decision register}
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

    protected $description = 'Design a read-only supplier preview feed profile; no remote resources, persistence, imports, writes, jobs, schedules, images, or Catalog Sync.';

    public function handle(SupplierPreviewFeedProfileDesigner $designer): int
    {
        $output = strtolower((string) ($this->option('output') ?: 'table'));
        if (! in_array($output, ['table', 'json'], true)) {
            $this->error('Unsupported output. Use table or json.');

            return self::FAILURE;
        }

        try {
            $report = $designer->design([
                'supplier' => $this->option('supplier'),
                'source' => $this->option('source'),
                'source_format' => $this->option('source-format'),
                'record_path' => $this->option('record-path'),
                'semantics_profile' => $this->option('semantics-profile'),
                'decision_register' => $this->option('decision-register'),
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
            $this->error('Preview feed profile design failed safely.');

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
        $this->info('Supplier preview feed profile design');
        $this->line('Read-only and non-persistent. No import, write, job, schedule change, image action, link action, or Catalog Sync was run.');
        $this->line('Verdict: '.($report['verdict'] ?? '-'));
        $this->table(['Metric', 'Value'], [
            ['Supplier', data_get($report, 'source_to_staging_reconciliation.supplier.key', '-')],
            ['Decision register', data_get($report, 'decision_register.key', '-')],
            ['Preview profile', data_get($report, 'preview_feed_profile.key', '-')],
            ['Source records', data_get($report, 'source_to_staging_reconciliation.source_aggregates.source_record_count', 0)],
            ['Staging records', data_get($report, 'source_to_staging_reconciliation.staging_aggregates.row_count', 0)],
            ['Potential CREATE classifications', data_get($report, 'aggregate_preview_counts.would_create', 0)],
            ['Potential UPDATE classifications', data_get($report, 'aggregate_preview_counts.would_update', 0)],
            ['Blocking human decisions', count((array) ($report['blocking_decision_ids'] ?? []))],
            ['Hard blockers', count((array) ($report['blockers'] ?? []))],
        ]);

        if (! $summaryOnly) {
            $this->table(['Guarantee', 'Value'], [
                ['Human review required', data_get($report, 'human_review_required', false) ? 'yes' : 'no'],
                ['Persisted profile created', data_get($report, 'persisted_profile_created', false) ? 'yes' : 'no'],
                ['Executable import configuration created', data_get($report, 'executable_import_configuration_created', false) ? 'yes' : 'no'],
                ['Import executed', data_get($report, 'import_executed', false) ? 'yes' : 'no'],
                ['Catalog Sync executed', data_get($report, 'catalog_sync_executed', false) ? 'yes' : 'no'],
            ]);
        }

        foreach ((array) ($report['records_changed'] ?? []) as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
