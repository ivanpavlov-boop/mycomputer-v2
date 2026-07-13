<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Onboarding\ControlledSupplierScheduleFreezeService;
use Illuminate\Console\Command;

final class ControlledSupplierScheduleFreeze extends Command
{
    protected $signature = 'suppliers:controlled-schedule-freeze
        {--supplier= : Required supplier id, slug, or exact company name}
        {--dry-run : Explicitly request dry-run mode (the default)}
        {--apply : Apply the single controlled schedule freeze}
        {--confirm-supplier= : Must match the resolved supplier slug}
        {--confirm-action= : Must be freeze-for-audit}
        {--confirm-write-scope= : Must be schedule-enabled-only}
        {--confirm-scheduler-stopped : Acknowledge that scheduler operations were stopped externally}
        {--expected-supplier-id=}
        {--expected-schedule-enabled=}
        {--expected-schedule-type=}
        {--expected-import-enabled=}
        {--expected-staged-count=}
        {--expected-linked-count=}
        {--expected-unlinked-count=}
        {--expected-last-import-at=}
        {--reason= : Required non-empty operator reason for apply mode}
        {--format=table : Output format: table or json}
        {--summary-only : Suppress detailed report sections in table output}
        {--issue-sample-limit=20 : Maximum refusal issue codes to display}';

    protected $description = 'Dry-run-first controlled supplier schedule freeze; schedule flag only, no imports or Catalog Sync.';

    public function handle(ControlledSupplierScheduleFreezeService $freeze): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $report = $freeze->freeze([
            'supplier' => filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            'apply' => $apply,
            'confirm_supplier' => $this->option('confirm-supplier'),
            'confirm_action' => $this->option('confirm-action'),
            'confirm_write_scope' => $this->option('confirm-write-scope'),
            'confirm_scheduler_stopped' => (bool) $this->option('confirm-scheduler-stopped'),
            'expected_supplier_id' => $this->option('expected-supplier-id'),
            'expected_schedule_enabled' => $this->option('expected-schedule-enabled'),
            'expected_schedule_type' => $this->option('expected-schedule-type'),
            'expected_import_enabled' => $this->option('expected-import-enabled'),
            'expected_staged_count' => $this->option('expected-staged-count'),
            'expected_linked_count' => $this->option('expected-linked-count'),
            'expected_unlinked_count' => $this->option('expected-unlinked-count'),
            'expected_last_import_at' => $this->option('expected-last-import-at'),
            'reason' => $this->option('reason'),
            'issue_sample_limit' => max(1, min((int) ($this->option('issue-sample-limit') ?: 20), 100)),
        ]);

        $payload = $report->toArray();

        if ($format === 'json') {
            try {
                $encoded = json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException $exception) {
                $this->error('Unable to serialize controlled freeze report: '.$exception->getMessage());

                return self::FAILURE;
            }

            $this->line($encoded);
        } else {
            $this->renderTable($payload, (bool) $this->option('summary-only'));
        }

        return (bool) ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $payload */
    private function renderTable(array $payload, bool $summaryOnly): void
    {
        $apply = (bool) ($payload['apply_requested'] ?? false);
        $this->info($apply ? 'Controlled supplier schedule freeze' : 'Controlled supplier schedule freeze dry-run');
        $this->line($apply
            ? 'Apply mode changes only suppliers.schedule_enabled from true to false after all guards pass.'
            : 'Dry-run only. No database records, jobs, imports, feeds, or Catalog Sync were changed.');
        $this->line('Verdict: '.($payload['verdict'] ?? 'unknown'));

        $supplier = $payload['supplier'] ?? null;
        $before = $payload['observed_state_before'] ?? [];
        $after = $payload['observed_state_after'] ?? [];
        $this->table(['Metric', 'Value'], [
            ['Supplier', is_array($supplier) ? (($supplier['key'] ?? '-').' (#'.($supplier['id'] ?? '-').')') : '-'],
            ['Dry-run', $this->boolLabel($payload['dry_run'] ?? false)],
            ['Apply requested', $this->boolLabel($payload['apply_requested'] ?? false)],
            ['Can apply', $this->boolLabel($payload['can_apply'] ?? false)],
            ['Schedule before', $this->boolLabel($before['schedule_enabled'] ?? null)],
            ['Schedule after', $this->boolLabel($after['schedule_enabled'] ?? null)],
            ['Active import state', $payload['active_import_check']['status'] ?? 'unknown'],
            ['Transaction attempted', $this->boolLabel($payload['transaction_attempted'] ?? false)],
            ['Transaction committed', $this->boolLabel($payload['transaction_committed'] ?? false)],
            ['Committed supplier changes', $payload['committed_supplier_changes'] ?? 0],
            ['Schedule state changed', $payload['schedule_state_changed'] ?? 0],
        ]);

        $refusals = $payload['refusal_reasons'] ?? [];
        $warnings = $payload['warnings'] ?? [];
        $this->line('Refusal reasons: '.($refusals === [] ? '-' : implode(', ', $refusals)));
        $this->line('Warnings: '.($warnings === [] ? '-' : implode(', ', $warnings)));

        if (! $summaryOnly) {
            $this->table(['Expected state', 'Observed before', 'Observed after'], [
                [
                    $this->compactState($payload['expected_state'] ?? []),
                    $this->compactState($before),
                    $this->compactState($after),
                ],
            ]);

            $this->line('Write scope: suppliers.schedule_enabled true -> false only.');
            $inspectedTables = implode(', ', $payload['active_import_check']['inspected_tables'] ?? []);
            $this->line('Active import tables: '.($inspectedTables !== '' ? $inspectedTables : '-'));

            foreach (($payload['records_changed'] ?? []) as $table => $count) {
                $this->line($table.' changed: '.$count);
            }
        }

        if ($apply && ($payload['success'] ?? false)) {
            $this->line('Only the selected supplier schedule flag was changed. No import or catalog operation was run.');
        }
    }

    private function boolLabel(mixed $value): string
    {
        return $value === null ? '-' : ($value ? 'yes' : 'no');
    }

    /** @param array<string, mixed> $state */
    private function compactState(array $state): string
    {
        if ($state === []) {
            return '-';
        }

        return collect($state)
            ->map(fn (mixed $value, string $key): string => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value))
            ->implode('; ');
    }
}
