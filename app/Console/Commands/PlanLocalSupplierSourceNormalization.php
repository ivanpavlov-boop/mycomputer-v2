<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Onboarding\LocalSupplierSourceNormalizationPlanner;
use Illuminate\Console\Command;
use Throwable;

final class PlanLocalSupplierSourceNormalization extends Command
{
    protected $signature = 'suppliers:plan-local-source-normalization
        {--supplier= : Required existing supplier key or ID}
        {--source= : Required local XML file path or synthetic test fixture}
        {--source-format=xml : Local source format; only xml is supported}
        {--record-path= : Optional repeating XML record path}
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
        {--sample-limit=20 : Reserved bounded sample limit; values are never emitted}
        {--issue-sample-limit=20 : Maximum bounded issue samples}';

    protected $description = 'Read-only local supplier source normalization planner; no remote resources, imports, writes, jobs, images, schedules, or Catalog Sync.';

    public function handle(LocalSupplierSourceNormalizationPlanner $planner): int
    {
        $output = strtolower((string) ($this->option('output') ?: 'table'));

        if (! in_array($output, ['table', 'json'], true)) {
            $this->error('Unsupported output. Use table or json.');

            return self::FAILURE;
        }

        try {
            $report = $planner->plan([
                'supplier' => $this->option('supplier'),
                'source' => $this->option('source'),
                'source_format' => $this->option('source-format'),
                'record_path' => $this->option('record-path'),
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
            $this->error('Local source normalization plan failed safely.');

            return self::FAILURE;
        }

        if ($output === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($report, (bool) $this->option('summary-only'));
        }

        return $report->success ? self::SUCCESS : self::FAILURE;
    }

    private function renderTable(object $report, bool $summaryOnly): void
    {
        $this->info('Local supplier source normalization plan');
        $this->line('Read-only. No remote resource, import, write, job, image action, schedule change, or Catalog Sync was run.');
        $this->line('Verdict: '.$report->verdict);
        $this->table(['Metric', 'Value'], [
            ['Supplier', $report->supplier['key'] ?? '-'],
            ['Source file', $report->source['file_name'] ?? '-'],
            ['Source SHA-256', $report->sourceFingerprint['sha256'] ?? '-'],
            ['Source records', $report->sourceRecordCount],
            ['Legacy staging records', $report->legacyStagingCount],
            ['Record count delta', $report->recordCountDelta],
            ['Schedule frozen', (($report->observedState['schedule_enabled'] ?? null) === false) ? 'yes' : 'no'],
            ['Active import state', $report->activeImportCheck['state'] ?? '-'],
            ['Blockers', count($report->blockers)],
            ['Warnings', count($report->warnings)],
        ]);

        if (! $summaryOnly) {
            $this->table(
                ['Field role', 'Source field', 'Coverage', 'Review required'],
                collect($report->fieldCoverage)
                    ->map(fn (array $coverage, string $role): array => [
                        $role,
                        $coverage['source_field_path'] ?? implode(', ', (array) ($coverage['source_field_paths'] ?? [])) ?: '-',
                        ($coverage['coverage_percentage'] ?? 0).'%',
                        ($coverage['review_required'] ?? true) ? 'yes' : 'no',
                    ])
                    ->values()
                    ->all(),
            );
        }

        foreach ($report->recordsChanged as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
