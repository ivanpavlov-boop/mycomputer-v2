<?php

namespace App\Console\Commands;

use App\Data\Suppliers\Onboarding\SupplierLegacyStagingAuditReport;
use App\Services\Suppliers\Onboarding\LegacySupplierStagingAuditService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

final class AuditLegacySupplierStagingState extends Command
{
    protected $signature = 'suppliers:audit-legacy-staging-state
        {--supplier= : Required supplier key, slug, ID, or exact company name}
        {--include-linked-analysis : Include linked catalog product analysis}
        {--include-status-counts : Include staging status diagnostics}
        {--include-identifier-diagnostics : Include bounded hashed identifier diagnostics}
        {--include-catalog-comparison : Include aggregate catalog comparison diagnostics}
        {--include-mapping-analysis : Include mapping state diagnostics}
        {--include-import-history : Include safe import and audit history diagnostics}
        {--output=table : Output format: table or json}
        {--summary-only : Suppress detailed tables in table output}
        {--sample-limit=20 : Maximum hashed diagnostic samples}
        {--issue-sample-limit=20 : Maximum issue samples}
        {--sort= : Optional deterministic presentation sort}';

    protected $description = 'Read-only legacy supplier staging audit; no import, linking, schedule changes, no writes, or Catalog Sync.';

    public function handle(LegacySupplierStagingAuditService $audit): int
    {
        $output = strtolower((string) ($this->option('output') ?: 'table'));

        if (! in_array($output, ['table', 'json'], true)) {
            $this->error('Unsupported output. Use table or json.');

            return self::FAILURE;
        }

        try {
            $report = $audit->audit([
                'supplier' => $this->option('supplier'),
                'include_linked_analysis' => (bool) $this->option('include-linked-analysis'),
                'include_status_counts' => (bool) $this->option('include-status-counts'),
                'include_identifier_diagnostics' => (bool) $this->option('include-identifier-diagnostics'),
                'include_catalog_comparison' => (bool) $this->option('include-catalog-comparison'),
                'include_mapping_analysis' => (bool) $this->option('include-mapping-analysis'),
                'include_import_history' => (bool) $this->option('include-import-history'),
                'sample_limit' => (int) ($this->option('sample-limit') ?: 20),
                'issue_sample_limit' => (int) ($this->option('issue-sample-limit') ?: 20),
                'sort' => $this->option('sort'),
            ]);
        } catch (InvalidArgumentException|RuntimeException) {
            $this->error('Legacy supplier staging audit failed safely.');

            return self::FAILURE;
        }

        if ($output === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($report, (bool) $this->option('summary-only'));
        }

        return in_array('unsafe_configuration', $report->blockers, true)
            || in_array('unexpected_mutation_detected', $report->blockers, true)
            || $report->verdict === 'audit_failed'
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function renderTable(SupplierLegacyStagingAuditReport $report, bool $summaryOnly): void
    {
        $this->info('Legacy supplier staging audit');
        $this->line('Read-only. No import, linking, schedule changes, writes, jobs, remote requests, images, or Catalog Sync were run.');
        $this->line('Verdict: '.$report->verdict);
        $this->line('Supplier: '.($report->supplier['key'] ?? 'unknown'));

        $this->table(['Metric', 'Value'], [
            ['Staging rows', $report->stagingInventory['total_rows'] ?? 0],
            ['Linked rows', $report->stagingInventory['linked_rows'] ?? 0],
            ['Unlinked rows', $report->stagingInventory['unlinked_rows'] ?? 0],
            ['Blockers', count($report->blockers)],
            ['Warnings', count($report->warnings)],
        ]);

        if (! $summaryOnly) {
            $this->table(['Blockers', 'Warnings'], [[implode(', ', $report->blockers) ?: '-', implode(', ', $report->warnings) ?: '-']]);
            $this->table(['Flag', 'Value'], collect($report->globalSafetyFlags)->map(fn (bool $value, string $key): array => [$key, $value ? 'yes' : 'no'])->values()->all());
        }

        foreach ($report->recordsChanged as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
