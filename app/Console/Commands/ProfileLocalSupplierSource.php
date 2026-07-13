<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Onboarding\LocalSupplierSourceProfiler;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

final class ProfileLocalSupplierSource extends Command
{
    protected $signature = 'suppliers:profile-local-source
        {--supplier= : Required supplier key}
        {--source= : Required local XML file path or test fixture}
        {--source-format=xml : Local source format; only xml is supported}
        {--record-path= : Optional repeating XML record path}
        {--expected-sha256= : Optional expected SHA-256 fingerprint}
        {--full-file : Parse the complete local file; streaming is always used}
        {--output=table : Output format: table or json}
        {--summary-only : Suppress detailed tables in table output}
        {--sample-limit=20 : Maximum bounded diagnostic samples}
        {--issue-sample-limit=20 : Maximum issue samples}';

    protected $description = 'Read-only local XML source profiler; no remote resources, imports, writes, jobs, or Catalog Sync.';

    public function handle(LocalSupplierSourceProfiler $profiler): int
    {
        $output = strtolower((string) ($this->option('output') ?: 'table'));

        if (! in_array($output, ['table', 'json'], true)) {
            $this->error('Unsupported output. Use table or json.');

            return self::FAILURE;
        }

        try {
            $report = $profiler->profile([
                'supplier' => $this->option('supplier'),
                'source' => $this->option('source'),
                'source_format' => $this->option('source-format'),
                'record_path' => $this->option('record-path'),
                'expected_sha256' => $this->option('expected-sha256'),
                'full_file' => (bool) $this->option('full-file'),
                'sample_limit' => (int) ($this->option('sample-limit') ?: 20),
                'issue_sample_limit' => (int) ($this->option('issue-sample-limit') ?: 20),
            ]);
        } catch (InvalidArgumentException|RuntimeException) {
            $this->error('Local source profile failed safely.');

            return self::FAILURE;
        }

        if ($output === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($report, (bool) $this->option('summary-only'));
        }

        return in_array($report->verdict, ['unsafe_configuration', 'invalid_local_source', 'source_fingerprint_mismatch', 'audit_failed'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function renderTable(object $report, bool $summaryOnly): void
    {
        $this->info('Local supplier source profile');
        $this->line('Read-only. No remote resource, import, write, job, image action, or Catalog Sync was run.');
        $this->line('Verdict: '.$report->verdict);
        $this->table(['Metric', 'Value'], [
            ['Source SHA-256', $report->sourceFingerprint['sha256'] ?? '-'],
            ['Record path', $report->parserResult['selected_record_path'] ?? '-'],
            ['Record count', $report->parserResult['total_record_count'] ?? 0],
            ['Full-file parse', ($report->parserResult['full_file_parse_completed'] ?? false) ? 'yes' : 'no'],
            ['Blockers', count($report->blockers)],
            ['Warnings', count($report->warnings)],
        ]);

        if (! $summaryOnly) {
            $this->table(['Role', 'Path'], collect($report->likelyFieldRoles)->except(['image_paths'])->map(fn (array $role, string $name): array => [$name, $role['path'] ?? '-'])->values()->all());
        }

        foreach ($report->recordsChanged as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
