<?php

namespace App\Console\Commands;

use App\Services\Suppliers\AsbisDualFeedPreviewService;
use Illuminate\Console\Command;

class PreviewAsbisDualFeed extends Command
{
    protected $signature = 'suppliers:preview-asbis-dual-feed
        {--supplier= : Required ASBIS supplier id, slug, or exact company name}
        {--product-list= : Required local ProductList.xml path unless --product-list-fixture is used}
        {--price-avail= : Required local PriceAvail.xml path unless --price-avail-fixture is used}
        {--product-list-fixture= : Local ProductList.xml fixture path}
        {--price-avail-fixture= : Local PriceAvail.xml fixture path}
        {--join-key=auto : Join key: auto or an explicit key used in both feeds}
        {--product-key= : Explicit ProductList join key}
        {--price-key= : Explicit PriceAvail join key}
        {--limit=50 : Maximum rows to display}
        {--max-rows=5000 : Maximum rows to scan, capped at 5000}
        {--format=table : Output format: table or json}
        {--show-field-map : Include detected field map diagnostics}
        {--show-raw-fields : Include raw field names for displayed rows}
        {--show-normalized : Include normalized diagnostics}
        {--show-identifiers : Include identifier summary}
        {--show-categories : Include category summary}
        {--show-unmatched : Include unmatched ProductList and PriceAvail rows}
        {--show-issues : Include row/source issues}';

    protected $description = 'Preview and join local ASBIS ProductList and PriceAvail feeds without writing data.';

    public function handle(AsbisDualFeedPreviewService $preview): int
    {
        $payload = $preview->run([
            'supplier' => filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            'product_list' => filled($this->option('product-list')) ? (string) $this->option('product-list') : null,
            'price_avail' => filled($this->option('price-avail')) ? (string) $this->option('price-avail') : null,
            'product_list_fixture' => filled($this->option('product-list-fixture')) ? (string) $this->option('product-list-fixture') : null,
            'price_avail_fixture' => filled($this->option('price-avail-fixture')) ? (string) $this->option('price-avail-fixture') : null,
            'join_key' => (string) ($this->option('join-key') ?: 'auto'),
            'product_key' => filled($this->option('product-key')) ? (string) $this->option('product-key') : null,
            'price_key' => filled($this->option('price-key')) ? (string) $this->option('price-key') : null,
            'limit' => (int) ($this->option('limit') ?: 50),
            'max_rows' => (int) ($this->option('max-rows') ?: 5000),
            'format' => strtolower((string) ($this->option('format') ?: 'table')),
            'show_field_map' => (bool) $this->option('show-field-map'),
            'show_raw_fields' => (bool) $this->option('show-raw-fields'),
            'show_normalized' => (bool) $this->option('show-normalized'),
            'show_identifiers' => (bool) $this->option('show-identifiers'),
            'show_categories' => (bool) $this->option('show-categories'),
            'show_unmatched' => (bool) $this->option('show-unmatched'),
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
        $this->info('ASBIS dual-feed preview');
        $this->line('Preview-only. No supplier_products writes, feed fetches, queue jobs, Catalog Sync, catalog products, categories, mappings, attributes, or schedules were changed.');

        if (! ($payload['success'] ?? false)) {
            $issue = $payload['issues'][0] ?? [];
            $this->error((string) ($issue['message'] ?? 'ASBIS dual-feed preview failed.'));
        }

        $summary = $payload['summary'] ?? [];

        $this->table([
            'Supplier',
            'ProductList source',
            'PriceAvail source',
            'Mode',
            'ProductList rows',
            'PriceAvail rows',
            'Joined rows',
            'Would create',
            'Would update',
            'Manual review',
            'Skipped',
            'Product-only',
            'Price-only',
            'Duplicate keys',
            'Cross-supplier matches',
            'Safety status',
        ], [[
            $summary['supplier_name'] ?? '-',
            $summary['product_list_source_label'] ?? '-',
            $summary['price_avail_source_label'] ?? '-',
            $summary['mode'] ?? '-',
            $summary['product_list_rows'] ?? 0,
            $summary['price_avail_rows'] ?? 0,
            $summary['joined_rows'] ?? 0,
            $summary['would_create'] ?? 0,
            $summary['would_update'] ?? 0,
            $summary['manual_review'] ?? 0,
            $summary['skipped'] ?? 0,
            $summary['product_only_rows'] ?? 0,
            $summary['price_only_rows'] ?? 0,
            $summary['duplicate_keys'] ?? 0,
            $summary['cross_supplier_matches'] ?? 0,
            $summary['safety_status'] ?? '-',
        ]]);

        $join = $payload['join'] ?? [];
        $this->line('Join confidence: '.($join['confidence'] ?? '-'));
        $this->line('ProductList key: '.($join['product_key'] ?? '-'));
        $this->line('PriceAvail key: '.($join['price_key'] ?? '-'));

        $rows = collect($payload['joined_rows'] ?? []);

        if ($rows->isNotEmpty()) {
            $this->line('');
            $this->info('Joined preview rows');
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
                'ProductList',
                'PriceAvail',
                'Future staging action',
                'Issues',
            ], $rows->map(fn (array $row): array => [
                $row['row_index'] ?? '-',
                $row['supplier_sku'] ?? '-',
                $row['ean_gtin'] ?? '-',
                $row['mpn'] ?? '-',
                $row['brand'] ?? '-',
                $this->shortText($row['name'] ?? null),
                $this->shortText($row['category'] ?? null),
                $this->formatMoney($row['price'] ?? null, $row['currency'] ?? null),
                $row['stock'] ?? '-',
                $row['availability'] ?? '-',
                ($row['product_list_present'] ?? false) ? 'yes' : 'no',
                ($row['price_avail_present'] ?? false) ? 'yes' : 'no',
                $row['future_staging_action'] ?? '-',
                $this->issuesLabel($row['issues'] ?? []),
            ])->all());
        }

        if ((bool) $this->option('show-field-map')) {
            $this->line('');
            $this->info('Detected ProductList fields');
            $this->keyValueTable($payload['detected_product_fields']['normalized_field_map'] ?? []);
            $this->line('');
            $this->info('Detected PriceAvail fields');
            $this->keyValueTable($payload['detected_price_fields']['normalized_field_map'] ?? []);
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

    private function formatMoney(mixed $amount, mixed $currency): string
    {
        if ($amount === null || $amount === '') {
            return '-';
        }

        return trim((string) $amount.' '.(string) ($currency ?: ''));
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
}
