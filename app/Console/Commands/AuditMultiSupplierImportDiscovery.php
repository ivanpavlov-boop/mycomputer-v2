<?php

namespace App\Console\Commands;

use App\Services\Suppliers\MultiSupplierImportDiscoveryService;
use Illuminate\Console\Command;

class AuditMultiSupplierImportDiscovery extends Command
{
    protected $signature = 'suppliers:audit-discovery
        {--supplier= : Limit to supplier id, slug, or exact company name}
        {--limit=50 : Maximum rows to display per discovery section}
        {--format=table : Output format: table or json}
        {--only-with-issues : Show only suppliers/categories with issues}
        {--include-empty : Include suppliers or category values without staging/category data}
        {--show-categories : Show distinct supplier category discovery rows}
        {--show-identifiers : Show identifier completeness details}
        {--show-overlaps : Show possible cross-supplier overlap rows}';

    protected $description = 'Read-only multi-supplier staging discovery audit.';

    public function handle(MultiSupplierImportDiscoveryService $discovery): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        $payload = $discovery->audit(
            supplier: filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            limit: (int) ($this->option('limit') ?: 50),
            onlyWithIssues: (bool) $this->option('only-with-issues'),
            includeEmpty: (bool) $this->option('include-empty'),
            showCategories: (bool) $this->option('show-categories'),
            showIdentifiers: (bool) $this->option('show-identifiers'),
            showOverlaps: (bool) $this->option('show-overlaps'),
        );

        return $format === 'json'
            ? $this->renderJson($payload)
            : $this->renderTable($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderJson(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderTable(array $payload): int
    {
        $this->info('Multi-supplier import discovery audit');
        $this->line('Read-only. No imports, sync, mapping approvals, or catalog writes were run.');

        $supplierRows = collect($payload['suppliers'] ?? []);

        if ($supplierRows->isEmpty()) {
            $this->warn('No suppliers matched the selected discovery filters.');
        }

        $this->table([
            'ID',
            'Supplier',
            'Status',
            'Staged',
            'SKU',
            'EAN',
            'MPN',
            'Brand',
            'Category',
            'Mapping status',
            'Dup SKU/EAN/MPN',
            'Overlap EAN/MPN',
            'Readiness',
        ], $supplierRows->map(fn (array $row): array => [
            $row['supplier_id'],
            $row['supplier_name'],
            $this->statusLabel($row),
            $row['staged_supplier_products_count'],
            $row['products_with_supplier_sku'],
            $row['products_with_ean_gtin_barcode'],
            $row['products_with_manufacturer_sku_mpn'],
            $row['products_with_brand_manufacturer'],
            $row['products_with_category_data'],
            $this->mappingStatusLabel($row['category_mapping_status_counts'] ?? []),
            sprintf(
                '%d/%d/%d',
                $row['duplicate_supplier_sku_count_inside_supplier'],
                $row['duplicate_ean_gtin_count_inside_supplier'],
                $row['duplicate_mpn_count_inside_supplier'],
            ),
            sprintf(
                '%d/%d',
                $row['overlapping_ean_gtin_with_other_suppliers'],
                $row['overlapping_mpn_with_other_suppliers'],
            ),
            $row['readiness_status'],
        ])->all());

        $summary = $payload['summary'];
        $this->line('Suppliers checked: '.$summary['suppliers_checked']);
        $this->line('Suppliers returned: '.$summary['suppliers_returned']);
        $this->line('Staged supplier_products: '.$summary['staged_supplier_products']);
        $this->line('Ready for mapping review: '.$summary['ready_for_mapping_review']);
        $this->line('Needs identifier cleanup: '.$summary['needs_identifier_cleanup']);
        $this->line('Needs category data: '.$summary['needs_category_data']);
        $this->line('Needs manual supplier setup: '.$summary['needs_manual_supplier_setup']);
        $this->line('No staging data: '.$summary['no_staging_data']);

        if ((bool) $this->option('show-categories')) {
            $this->renderCategories($payload['categories'] ?? []);
        }

        if ((bool) $this->option('show-identifiers')) {
            $this->renderIdentifierSummary($payload['identifier_summary'] ?? []);
        }

        if ((bool) $this->option('show-overlaps')) {
            $this->renderOverlaps($payload['overlaps'] ?? []);
        }

        $this->zeroChangeCounters($payload['records_changed']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function statusLabel(array $row): string
    {
        $parts = [
            $row['supplier_status'] ?? 'unknown',
        ];

        if ($row['import_enabled'] !== null) {
            $parts[] = $row['import_enabled'] ? 'import on' : 'import off';
        }

        if ($row['schedule_enabled'] !== null) {
            $parts[] = $row['schedule_enabled'] ? 'schedule on' : 'schedule off';
        }

        return implode(' / ', $parts);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function mappingStatusLabel(array $counts): string
    {
        return sprintf(
            'A:%d P:%d R:%d I:%d U:%d',
            $counts['approved'] ?? 0,
            $counts['pending_review'] ?? 0,
            $counts['rejected'] ?? 0,
            $counts['ignored'] ?? 0,
            $counts['unmapped'] ?? 0,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function renderCategories(array $categories): void
    {
        $this->line('');
        $this->info('Supplier category discovery');

        if ($categories === []) {
            $this->line('- none');

            return;
        }

        $this->table([
            'Supplier',
            'Supplier category',
            'Products',
            'Mapping status',
            'Family',
            'Confidence',
            'Reviewed',
            'Next action',
        ], collect($categories)->map(fn (array $row): array => [
            $row['supplier_name'],
            $row['supplier_category_name'],
            $row['staged_products_count'],
            $row['mapping_status'],
            $row['canonical_product_family'],
            $row['confidence'],
            $row['reviewed_status'],
            $row['next_action'],
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderIdentifierSummary(array $summary): void
    {
        $this->line('');
        $this->info('Identifier discovery');
        $this->line('Products with supplier SKU: '.($summary['products_with_supplier_sku'] ?? 0));
        $this->line('Products missing supplier SKU: '.($summary['products_missing_supplier_sku'] ?? 0));
        $this->line('Products with EAN/GTIN: '.($summary['products_with_ean_gtin'] ?? 0));
        $this->line('Products missing EAN/GTIN: '.($summary['products_missing_ean_gtin'] ?? 0));
        $this->line('Products with MPN: '.($summary['products_with_mpn'] ?? 0));
        $this->line('Products missing MPN: '.($summary['products_missing_mpn'] ?? 0));
        $this->line('Products with brand: '.($summary['products_with_brand'] ?? 0));
        $this->line('Products missing brand: '.($summary['products_missing_brand'] ?? 0));
        $this->line('Duplicate supplier SKU within supplier: '.($summary['duplicate_supplier_sku_within_supplier'] ?? 0));
        $this->line('Duplicate EAN/GTIN within supplier: '.($summary['duplicate_ean_gtin_within_supplier'] ?? 0));
        $this->line('Duplicate MPN within supplier: '.($summary['duplicate_mpn_within_supplier'] ?? 0));
        $this->line('Same EAN/GTIN across suppliers: '.($summary['same_ean_gtin_across_suppliers'] ?? 0));
        $this->line('Same MPN across suppliers: '.($summary['same_mpn_across_suppliers'] ?? 0));
        $this->line('Same brand + MPN across suppliers: '.($summary['same_brand_mpn_across_suppliers'] ?? 0));
        $this->line('Same normalized name across suppliers: '.($summary['same_normalized_name_across_suppliers'] ?? 0));
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlaps
     */
    private function renderOverlaps(array $overlaps): void
    {
        $this->line('');
        $this->info('Cross-supplier overlap discovery');

        if ($overlaps === []) {
            $this->line('- none');

            return;
        }

        $this->table([
            'Type',
            'Identifier',
            'Suppliers',
            'Rows',
            'Confidence',
            'Next action',
        ], collect($overlaps)->map(fn (array $row): array => [
            $row['identifier_type'],
            $row['identifier_value'],
            implode(', ', $row['suppliers_involved']),
            $row['supplier_products_count'],
            $row['confidence'],
            $row['next_action'],
        ])->all());
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
