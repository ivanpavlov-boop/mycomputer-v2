<?php

namespace App\Console\Commands;

use App\Data\Suppliers\Onboarding\ReadinessVerdict;
use App\Data\Suppliers\Onboarding\SupplierReadinessMatrixReport;
use App\Data\Suppliers\Onboarding\SupplierReadinessMatrixRow;
use App\Data\Suppliers\Onboarding\ValidationIssue;
use App\Services\Suppliers\Onboarding\SupplierReadinessMatrixService;
use Illuminate\Console\Command;

class AuditSupplierOnboardingReadinessMatrix extends Command
{
    protected $signature = 'suppliers:audit-onboarding-readiness-matrix
        {--supplier= : Limit to supplier key, slug, or exact company name}
        {--active-only : Include active suppliers only}
        {--include-disabled : Explicitly include disabled suppliers; all suppliers are included by default}
        {--include-staging-counts : Include bounded staging status and hashed SKU diagnostics}
        {--include-mapping-counts : Include mapping status counts}
        {--format=table : Output format: table or json}
        {--summary-only : Suppress the supplier matrix table}
        {--sample-limit=20 : Maximum hashed staging SKU samples per supplier}
        {--issue-sample-limit=20 : Maximum report issue samples}
        {--sort=readiness : Sort by readiness, supplier, staging, or blockers}
        {--direction=asc : Sort direction: asc or desc}';

    protected $description = 'Read-only supplier onboarding readiness matrix; no feed requests, no imports, no writes, and no Catalog Sync.';

    public function handle(SupplierReadinessMatrixService $matrix): int
    {
        $format = strtolower((string) ($this->option('format') ?: 'table'));
        $sort = strtolower((string) ($this->option('sort') ?: 'readiness'));
        $direction = strtolower((string) ($this->option('direction') ?: 'asc'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        if (! in_array($sort, ['readiness', 'supplier', 'staging', 'blockers'], true)) {
            $this->error('Unsupported sort. Use readiness, supplier, staging, or blockers.');

            return self::FAILURE;
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $this->error('Unsupported direction. Use asc or desc.');

            return self::FAILURE;
        }

        $report = $matrix->audit([
            'supplier' => $this->option('supplier'),
            'active_only' => (bool) $this->option('active-only'),
            'include_disabled' => (bool) $this->option('include-disabled'),
            'include_staging_counts' => (bool) $this->option('include-staging-counts'),
            'include_mapping_counts' => (bool) $this->option('include-mapping-counts'),
            'sample_limit' => (int) ($this->option('sample-limit') ?: 20),
            'issue_sample_limit' => (int) ($this->option('issue-sample-limit') ?: 20),
            'sort' => $sort,
            'direction' => $direction,
        ]);

        if ($format === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($report, (bool) $this->option('summary-only'));
        }

        return $report->matrixVerdict === ReadinessVerdict::UNSAFE_CONFIGURATION
            || $report->matrixVerdict === ReadinessVerdict::AUDIT_FAILED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function renderTable(SupplierReadinessMatrixReport $report, bool $summaryOnly): void
    {
        $this->info('Multi-supplier onboarding readiness matrix');
        $this->line('Read-only. No feed requests, imports, writes, Catalog Sync, jobs, schedules, or image actions were run.');
        $this->line('Matrix verdict: '.$report->matrixVerdict->value);

        if (! $summaryOnly) {
            $this->table([
                'Supplier',
                'Active',
                'Import',
                'Schedule',
                'Source',
                'Auth',
                'Driver',
                'Profile',
                'Staged',
                'Linked',
                'Stage',
                'Score',
                'Blockers',
                'Next action',
            ], collect($report->suppliers)->map(fn (SupplierReadinessMatrixRow $row): array => [
                $row->supplierName,
                $row->active ? 'yes' : 'no',
                $this->boolLabel($row->importEnabled),
                $this->boolLabel($row->scheduleEnabled),
                $row->sourceFormat,
                $this->boolLabel($row->authenticationConfigured),
                $row->driverAvailable ? ($row->driverKey ?? 'yes') : 'no',
                $row->feedProfileAvailable ? ($row->feedProfileKey ?? 'yes') : 'no',
                $row->stagingRowCount,
                $row->linkedStagingRowCount,
                $row->readinessStage->value,
                $row->readinessScore,
                $this->issueCodes($row->blockers),
                $row->nextSafeAction,
            ])->all());
        }

        $this->table(['Metric', 'Value'], [
            ['Suppliers', $report->supplierCount],
            ['Active suppliers', $report->activeSupplierCount],
            ['Disabled suppliers', $report->disabledSupplierCount],
            ['Source configured', $report->sourceConfiguredCount],
            ['Driver available', $report->driverAvailableCount],
            ['Profile available', $report->profileAvailableCount],
            ['Preview capable', $report->previewCapableCount],
            ['Controlled staging capable', $report->controlledStagingCapableCount],
            ['Post-apply verification capable', $report->postApplyVerificationCapableCount],
            ['Total staged rows', $report->totalStagingRows],
            ['Total linked staged rows', $report->totalLinkedStagingRows],
        ]);

        $this->line('Catalog CREATE enabled: '.($report->globalSafetyFlags['catalog_sync_create_enabled'] ? 'yes' : 'no'));
        $this->line('Catalog UPDATE enabled: '.($report->globalSafetyFlags['catalog_sync_update_enabled'] ? 'yes' : 'no'));
        $this->line('Sync All enabled: '.($report->globalSafetyFlags['catalog_sync_sync_all_enabled'] ? 'yes' : 'no'));
        $this->line('Automatic sync enabled: '.($report->globalSafetyFlags['catalog_sync_auto_enabled'] ? 'yes' : 'no'));

        foreach ($report->recordsChanged as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }

    private function boolLabel(?bool $value): string
    {
        return $value === null ? 'unknown' : ($value ? 'yes' : 'no');
    }

    /** @param array<int, ValidationIssue> $issues */
    private function issueCodes(array $issues): string
    {
        $codes = array_map(fn (ValidationIssue $issue): string => $issue->code, $issues);

        return $codes === [] ? '-' : implode(', ', $codes);
    }
}
