<?php

namespace App\Console\Commands;

use App\Services\Suppliers\ControlledSupplierStagingImportService;
use Illuminate\Console\Command;

class ControlledSupplierStagingImport extends Command
{
    protected $signature = 'suppliers:controlled-staging-import
        {--supplier= : Required supplier id, slug, or exact company name}
        {--source= : Required local source path unless --fixture is used. Remote HTTP/HTTPS sources are refused}
        {--fixture= : Local test/development fixture path}
        {--source-type=auto : Source type: xml, csv, json, or auto}
        {--limit=50 : Maximum preview/applied rows to display}
        {--max-rows=5000 : Maximum rows to scan, capped at 5000}
        {--format=table : Output format: table or json}
        {--dry-run : Explicitly request dry-run mode}
        {--apply : Write eligible ASBIS rows to supplier_products staging}
        {--confirm-supplier= : Required exact confirmation for apply, currently asbis}
        {--skip-invalid-rows : Skip invalid rows instead of failing the whole command}
        {--strict : Report strict mode intent; apply remains guarded}
        {--show-raw-fields : Include raw field diagnostics in JSON-compatible payloads where available}
        {--show-normalized : Include normalized diagnostics in JSON-compatible payloads where available}
        {--show-identifiers : Include identifier diagnostics}
        {--show-categories : Include category diagnostics}
        {--show-issues : Include row/source issues}';

    protected $description = 'Dry-run or explicitly apply a controlled supplier_products staging import for ASBIS only.';

    public function handle(ControlledSupplierStagingImportService $service): int
    {
        $payload = $service->run([
            'supplier' => filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            'source' => filled($this->option('source')) ? (string) $this->option('source') : null,
            'fixture' => filled($this->option('fixture')) ? (string) $this->option('fixture') : null,
            'source_type' => (string) ($this->option('source-type') ?: 'auto'),
            'limit' => (int) ($this->option('limit') ?: 50),
            'max_rows' => (int) ($this->option('max-rows') ?: 5000),
            'format' => strtolower((string) ($this->option('format') ?: 'table')),
            'dry_run' => (bool) $this->option('dry-run'),
            'apply' => (bool) $this->option('apply'),
            'confirm_supplier' => filled($this->option('confirm-supplier')) ? (string) $this->option('confirm-supplier') : null,
            'skip_invalid_rows' => (bool) $this->option('skip-invalid-rows'),
            'strict' => (bool) $this->option('strict'),
            'show_raw_fields' => (bool) $this->option('show-raw-fields'),
            'show_normalized' => (bool) $this->option('show-normalized'),
            'show_identifiers' => (bool) $this->option('show-identifiers'),
            'show_categories' => (bool) $this->option('show-categories'),
            'show_issues' => (bool) $this->option('show-issues'),
        ]);

        if (strtolower((string) $this->option('format')) === 'json') {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        return $this->renderTable($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderTable(array $payload): int
    {
        $this->info('Controlled supplier staging import');

        if (($payload['mode'] ?? 'dry_run') === 'dry_run') {
            $this->line('Dry-run. No supplier_products writes, feed fetches, queue jobs, Catalog Sync, or catalog writes were run.');
        } else {
            $this->line('Apply mode. Writes are limited to ASBIS supplier_products staging rows only.');
        }

        $summary = $payload['summary'] ?? [];

        if (! ($payload['success'] ?? false)) {
            $issue = $payload['issues'][0] ?? [];
            $this->error((string) ($issue['message'] ?? 'Controlled staging import failed.'));
        }

        $this->table([
            'Supplier',
            'Source type',
            'Mode',
            'Rows scanned',
            'Rows valid',
            'Rows skipped',
            'Would create',
            'Would update',
            'Created',
            'Updated',
            'Manual review',
            'Duplicate rows',
            'Cross-supplier matches',
            'Safety status',
        ], [[
            $summary['supplier_name'] ?? '-',
            $summary['source_type'] ?? '-',
            $summary['mode'] ?? '-',
            $summary['rows_scanned'] ?? 0,
            $summary['rows_valid'] ?? 0,
            $summary['rows_skipped'] ?? 0,
            $summary['would_create'] ?? 0,
            $summary['would_update'] ?? 0,
            $summary['created'] ?? 0,
            $summary['updated'] ?? 0,
            $summary['manual_review'] ?? 0,
            $summary['duplicate_rows'] ?? 0,
            $summary['cross_supplier_matches'] ?? 0,
            $summary['safety_status'] ?? '-',
        ]]);

        $rows = collect($payload['preview_rows'] ?? []);

        if ($rows->isNotEmpty()) {
            $this->line('');
            $this->info('Preview rows');
            $this->table([
                '#',
                'SKU',
                'EAN/GTIN',
                'MPN',
                'Brand',
                'Name',
                'Category',
                'Price',
                'Stock',
                'Availability',
                'Action',
                'Apply',
                'Issues',
            ], $rows->map(fn (array $row): array => [
                $row['row_index'] ?? '-',
                $row['supplier_sku'] ?? '-',
                $row['ean_gtin'] ?? '-',
                $row['mpn'] ?? '-',
                $row['brand'] ?? '-',
                $this->shortText($row['name'] ?? null),
                $this->shortText($row['category'] ?? null),
                $row['price'] ?? '-',
                $row['stock'] ?? '-',
                $row['availability'] ?? '-',
                $row['future_staging_action'] ?? '-',
                ($row['eligible_for_apply'] ?? false) ? 'yes' : 'no',
                $this->issuesLabel($row['issues'] ?? []),
            ])->all());
        }

        if ((bool) $this->option('show-issues')) {
            $this->line('');
            $this->info('Issues');
            $this->table(['Type', 'Row', 'Reason'], collect($payload['issues'] ?? [])->map(fn (array $issue): array => [
                $issue['type'] ?? '-',
                $issue['row_index'] ?? '-',
                $issue['reason'] ?? '-',
            ])->all());
        }

        foreach (($payload['records_changed'] ?? []) as $table => $count) {
            $this->line($table.' changed: '.$count);
        }

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function shortText(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '-';
        }

        return str($value)->limit(48)->toString();
    }

    /**
     * @param  array<int, string>  $issues
     */
    private function issuesLabel(array $issues): string
    {
        return $issues === [] ? '-' : implode(', ', $issues);
    }
}
