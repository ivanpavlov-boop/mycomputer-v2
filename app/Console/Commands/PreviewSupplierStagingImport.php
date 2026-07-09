<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierStagingImportPreviewService;
use Illuminate\Console\Command;

class PreviewSupplierStagingImport extends Command
{
    protected $signature = 'suppliers:preview-staging-import
        {--supplier= : Supplier id, slug, or exact company name for read-only comparison}
        {--source= : Local preview source path. Remote HTTP/HTTPS sources are refused}
        {--source-type=auto : Source type: xml, csv, json, or auto}
        {--limit=50 : Maximum preview rows to display}
        {--format=table : Output format: table or json}
        {--show-raw-fields : Include raw field names for displayed rows}
        {--show-normalized : Include normalized preview fields}
        {--show-identifiers : Include identifier summary}
        {--show-categories : Include category summary}
        {--show-issues : Include row/source issues}
        {--fixture= : Local test/development fixture path}';

    protected $description = 'Preview a supplier feed file for future supplier_products staging without writing data.';

    public function handle(SupplierStagingImportPreviewService $preview): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        $payload = $preview->preview(
            supplier: filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            source: filled($this->option('source')) ? (string) $this->option('source') : null,
            fixture: filled($this->option('fixture')) ? (string) $this->option('fixture') : null,
            sourceType: (string) ($this->option('source-type') ?: 'auto'),
            limit: (int) ($this->option('limit') ?: 50),
            showRawFields: (bool) $this->option('show-raw-fields'),
            showNormalized: (bool) $this->option('show-normalized'),
            showIdentifiers: (bool) $this->option('show-identifiers'),
            showCategories: (bool) $this->option('show-categories'),
            showIssues: (bool) $this->option('show-issues'),
        );

        if ($format === 'json') {
            return $this->renderJson($payload);
        }

        return $this->renderTable($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderJson(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderTable(array $payload): int
    {
        $this->info('Supplier staging import preview');
        $this->line('Read-only. No supplier_products writes, imports, feed fetches, queue jobs, Catalog Sync, or catalog writes were run.');

        if (! ($payload['success'] ?? false)) {
            $issue = $payload['issues'][0] ?? [];
            $this->error((string) ($issue['message'] ?? 'Unable to run preview.'));
            $this->zeroChangeCounters($payload['records_changed']);
            $this->line('catalog_sync changed: '.$payload['summary']['catalog_sync_changed']);

            return self::FAILURE;
        }

        $summary = $payload['summary'];

        $this->table([
            'Supplier',
            'Source type',
            'Rows scanned',
            'SKU coverage',
            'EAN coverage',
            'MPN coverage',
            'Brand coverage',
            'Category coverage',
            'Price coverage',
            'Stock coverage',
            'Would create',
            'Would update',
            'Cross-supplier matches',
            'Issues',
        ], [[
            $summary['supplier_name'],
            $summary['source_type'],
            $summary['rows_scanned'],
            $this->coverageLabel($payload, 'supplier_sku'),
            $this->coverageLabel($payload, 'ean_gtin'),
            $this->coverageLabel($payload, 'mpn'),
            $this->coverageLabel($payload, 'brand'),
            $this->coverageLabel($payload, 'category'),
            $this->coverageLabel($payload, 'price'),
            $this->coverageLabel($payload, 'stock'),
            $summary['would_create_supplier_products'],
            $summary['would_update_supplier_products'],
            $summary['possible_cross_supplier_matches'],
            count($payload['issues'] ?? []),
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
                'Future staging action',
                'Issues',
            ], $rows->map(fn (array $row): array => [
                $row['row_index'],
                $row['supplier_sku'] ?? '-',
                $row['ean_gtin'] ?? '-',
                $row['mpn'] ?? '-',
                $row['brand'] ?? '-',
                $this->shortText($row['name'] ?? null),
                $this->shortText($row['category'] ?? null),
                $row['price'] ?? '-',
                $row['stock'] ?? '-',
                $row['availability'] ?? '-',
                $row['future_staging_action'],
                $this->issuesLabel($row['issues'] ?? []),
            ])->all());
        }

        if ((bool) $this->option('show-identifiers')) {
            $this->line('');
            $this->info('Identifier summary');
            $this->keyValueTable($payload['identifier_summary'] ?? []);
        }

        if ((bool) $this->option('show-categories')) {
            $this->line('');
            $this->info('Category summary');
            $this->keyValueTable($payload['category_summary'] ?? []);
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

        $this->zeroChangeCounters($payload['records_changed']);
        $this->line('catalog_sync changed: '.$summary['catalog_sync_changed']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function coverageLabel(array $payload, string $field): string
    {
        $coverage = $payload['normalized_coverage'][$field] ?? null;

        if (! is_array($coverage)) {
            return '0/0';
        }

        return $coverage['present'].'/'.($coverage['present'] + $coverage['missing']);
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

    /**
     * @param  array<string, mixed>  $values
     */
    private function keyValueTable(array $values): void
    {
        $this->table(['Key', 'Value'], collect($values)->map(fn (mixed $value, string $key): array => [
            $key,
            is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value,
        ])->values()->all());
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
